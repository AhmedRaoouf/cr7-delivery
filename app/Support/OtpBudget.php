<?php

namespace App\Support;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Notification;
use App\Models\SmsSend;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Batch 68 — the platform's own ceiling on paid messages: the circuit breaker.
 *
 * Per-number and per-address counts stop one caller. They do not stop a thousand callers
 * each staying under them, which is what SMS pumping looks like, and the gateway bills
 * every one. `otp.daily_budget` and `otp.hourly_budget` count **real** gateway sends only —
 * a test-mode code and a stranger's shadow send cost nothing — and past either one the
 * answer is `503 otp_unavailable` until the window has room again.
 *
 * **Never for the operator.** A code whose account is an admin, for a password reset or a
 * password change, is sent whatever the budget says: an attack that trips the breaker must
 * not also lock the operator out of the panel that turns it back off. A customer's
 * password-change code is not one — nothing checks it (D-107), and a customer account
 * must not be a way around the breaker.
 *
 * The operators hear about it: once a day at ‪80‬% of the daily budget, and once a day per
 * window when it trips. The «once» is read back from the notification rows themselves, so
 * it survives a cache clear like everything else here.
 */
final class OtpBudget
{
    public const WARNING = 'sms.budget_warning_admin';

    public const DAILY_REACHED = 'sms.budget_reached_admin';

    public const HOURLY_REACHED = 'sms.hourly_budget_reached_admin';

    public static function daily(): int
    {
        return max(0, SettingsCatalog::int('otp.daily_budget'));
    }

    public static function hourly(): int
    {
        return max(0, SettingsCatalog::int('otp.hourly_budget'));
    }

    /** Real gateway sends since midnight on the business clock. */
    public static function sentToday(): int
    {
        return SmsSend::query()
            ->where('kind', SmsSend::KIND_REAL)
            ->where('created_at', '>=', BusinessTime::startOfToday())
            ->count();
    }

    /** Real gateway sends in the last sixty minutes. */
    public static function sentLastHour(): int
    {
        return SmsSend::query()
            ->where('kind', SmsSend::KIND_REAL)
            ->where('created_at', '>', now()->subSeconds(OtpGuard::HOUR))
            ->count();
    }

    /** @throws ApiException 503 otp_unavailable */
    public static function assertOpen(): void
    {
        if (self::sentToday() >= self::daily()) {
            self::alertOnce(self::DAILY_REACHED, ['budget' => self::daily()]);

            throw self::unavailable(BusinessTime::now()->addDay()->startOfDay()->utc());
        }

        $opens = OtpGuard::slotOpensAt(
            SmsSend::query()->where('kind', SmsSend::KIND_REAL),
            OtpGuard::HOUR,
            self::hourly(),
        );

        if ($opens->greaterThan(now())) {
            self::alertOnce(self::HOURLY_REACHED, ['budget' => self::hourly()]);

            throw self::unavailable($opens);
        }
    }

    /** After a real send: the ‪80‬% warning, once a day. */
    public static function afterRealSend(): void
    {
        $budget = self::daily();

        if ($budget <= 0) {
            return;
        }

        $sent = self::sentToday();

        if ($sent * 5 >= $budget * 4) {
            self::alertOnce(self::WARNING, ['sent' => $sent, 'budget' => $budget]);
        }
    }

    private static function unavailable(CarbonInterface $availableAt): ApiException
    {
        return OtpGuard::refusal('otp_unavailable', 'خدمة رسائل التحقق متوقفة مؤقتًا، حاول لاحقًا', 503, $availableAt);
    }

    /**
     * To every active global admin, unless this alert already went out today. Global only:
     * the budget is the whole platform's, and the panel that moves it is global-only
     * (doc 12 §10.1).
     *
     * @param  array<string, int>  $context
     */
    private static function alertOnce(string $type, array $context): void
    {
        try {
            if (Notification::query()->where('type', $type)->where('created_at', '>=', BusinessTime::startOfToday())->exists()) {
                return;
            }

            Log::warning('[OTP] '.$type, $context + ['sent_today' => self::sentToday(), 'sent_last_hour' => self::sentLastHour()]);

            $dispatcher = app(NotificationDispatcher::class);

            User::query()
                ->where('type', UserType::Admin)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $admin) => AdminScope::isGlobal($admin))
                ->each(fn (User $admin) => $dispatcher->send($admin, $type, $context, '/settings', null, ['kind' => 'sms_budget']));
        } catch (Throwable $e) {
            // An alert is never worth failing the send — or the refusal — that raised it.
            Log::warning('sms budget alert failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
