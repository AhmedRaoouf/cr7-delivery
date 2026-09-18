<?php

namespace App\Support;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpBlock;
use App\Models\SmsSend;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch 68 — «واعمل جد لل sms … المهم تحافظ علي الفلوس».
 *
 * Every rule that decides whether a verification code may be sent, or checked, is here,
 * and every number it reads is the operator's (`otp.*` on `ADM-46`). All of them are
 * counted from the `sms_sends` ledger and the `otp_blocks` table — the database, not the
 * cache — so a cache clear, a deploy or a restart hands nobody a fresh allowance.
 *
 * **Per number** — the protection that matters. Egyptian carriers put whole towns behind
 * one address, so the per-address counts have to stay wide and the per-number ones do the
 * work:
 *
 *   * the wait before the next code for the same purpose **doubles** with every send in
 *     24 hours — ‪60‬, ‪120‬, ‪240‬, ‪480‬, then ‪900‬ seconds with the defaults — and starts
 *     over when a correct code is entered;
 *   * `otp.max_per_hour` codes an hour and `otp.max_per_day` in 24 hours, every purpose
 *     together; the request past the daily count **blocks** the number for
 *     `otp.block_hours`;
 *   * `otp.max_failed_codes` codes guessed dead in 24 hours block it too.
 *
 * **Per address and per install** — `otp.ip_max_per_hour`, `otp.ip_max_per_day` and
 * `otp.device_max_per_day` stop one caller walking a list of numbers. They refuse; they
 * never block a number, which the caller does not own.
 *
 * **The owner's own password change** (an admin changing their password from a signed-in
 * session, D-107) is held only by its own escalating wait and its own hourly count. A
 * stranger who knows the admin's number can run up the anonymous counts on it, and must
 * not thereby lock the operator out of the one flow that needs a live session **and** the
 * current password.
 *
 * Every refusal carries `meta.retry_after` (seconds) and `meta.available_at` (ISO-8601,
 * UTC) — see {@see self::when()}.
 */
final class OtpGuard
{
    /** Every account code is six digits — the validators, the test code and the clients say so. */
    public const CODE_LENGTH = 6;

    public const HOUR = 3600;

    public const DAY = 86400;

    private const COOLDOWN = 'cooldown';

    private const HOURLY = 'hourly';

    // -------------------------------------------------------------- the panel's numbers

    public static function resendSeconds(): int
    {
        return max(0, SettingsCatalog::int('otp.resend_seconds'));
    }

    public static function resendMaxSeconds(): int
    {
        return max(self::resendSeconds(), SettingsCatalog::int('otp.resend_max_seconds'));
    }

    public static function ttlSeconds(): int
    {
        return max(1, SettingsCatalog::int('otp.ttl_minutes')) * 60;
    }

    public static function maxAttempts(): int
    {
        return max(1, SettingsCatalog::int('otp.max_attempts'));
    }

    public static function maxPerHour(): int
    {
        return max(1, SettingsCatalog::int('otp.max_per_hour'));
    }

    public static function maxPerDay(): int
    {
        return max(1, SettingsCatalog::int('otp.max_per_day'));
    }

    public static function blockHours(): int
    {
        return max(1, SettingsCatalog::int('otp.block_hours'));
    }

    public static function maxFailedCodes(): int
    {
        return max(1, SettingsCatalog::int('otp.max_failed_codes'));
    }

    public static function ipMaxPerHour(): int
    {
        return max(1, SettingsCatalog::int('otp.ip_max_per_hour'));
    }

    public static function ipMaxPerDay(): int
    {
        return max(1, SettingsCatalog::int('otp.ip_max_per_day'));
    }

    public static function deviceMaxPerDay(): int
    {
        return max(1, SettingsCatalog::int('otp.device_max_per_day'));
    }

    /**
     * The wait after the n-th send for one number and purpose (n ≥ 1):
     * `min(resend × 2^(n−1), resend_max)`.
     */
    public static function cooldownAfter(int $sends): int
    {
        if ($sends <= 0) {
            return 0;
        }

        return (int) min(self::resendSeconds() * (2 ** min($sends - 1, 20)), self::resendMaxSeconds());
    }

    // ------------------------------------------------------------------------ blocks

    public static function activeBlock(string $phone): ?OtpBlock
    {
        return OtpBlock::query()->active()->where('phone', $phone)->orderByDesc('blocked_until')->first();
    }

    /** @throws ApiException 423 otp_phone_blocked */
    public static function assertNotBlocked(string $phone): void
    {
        $block = self::activeBlock($phone);

        if ($block !== null) {
            throw self::blocked($block);
        }
    }

    public static function block(string $phone, string $reason, CarbonInterface $until, ?string $note = null, ?User $by = null): OtpBlock
    {
        return OtpBlock::query()->create([
            'phone' => $phone,
            'reason' => $reason,
            'blocked_until' => $until,
            'note' => $note,
            'created_by' => $by?->id,
        ]);
    }

    /**
     * Lifts every block on the number and starts its own counts over — hourly, daily,
     * the escalating wait and the burned codes. The per-address and per-install counts
     * are not the number's and stay.
     */
    public static function release(string $phone, ?User $by = null): void
    {
        OtpBlock::query()->active()->where('phone', $phone)->update([
            'lifted_at' => now(),
            'lifted_by' => $by?->id,
        ]);

        SmsSend::query()->where('phone', $phone)->whereNull('cleared_at')->update(['cleared_at' => now()]);
    }

    // --------------------------------------------------------------------- admission

    /**
     * Whether a code may go to this number now, for this caller. Nothing is written
     * unless the answer is a block — the request past the daily count is itself what
     * blocks the number.
     *
     * @param  bool  $owner  a signed-in admin's own password-change code — see the class note
     *
     * @throws ApiException otp_phone_blocked · otp_resend_cooldown · otp_send_rate_limited
     */
    public static function admit(string $phone, OtpPurpose $purpose, OtpRequester $requester, bool $owner = false): void
    {
        if (! $owner) {
            self::assertNotBlocked($phone);
        }

        [$availableAt, $binding] = self::nextSendAt($phone, $purpose, $owner);

        if ($availableAt->greaterThan(now())) {
            throw $binding === self::COOLDOWN
                ? self::refusal('otp_resend_cooldown', 'يرجى الانتظار قبل إعادة الإرسال', 429, $availableAt)
                : self::rateLimited($availableAt);
        }

        if ($owner) {
            return;
        }

        $sentToday = self::phoneSends($phone)->where('created_at', '>', now()->subSeconds(self::DAY))->count();

        if ($sentToday >= self::maxPerDay()) {
            throw self::blocked(self::block($phone, OtpBlock::REASON_DAILY_CAP, now()->addHours(self::blockHours())));
        }

        if ($requester->ip !== null) {
            $byIp = SmsSend::query()->where('ip', $requester->ip);
            self::assertRoom($byIp, self::HOUR, self::ipMaxPerHour());
            self::assertRoom($byIp, self::DAY, self::ipMaxPerDay());
        }

        if ($requester->deviceId !== null) {
            self::assertRoom(SmsSend::query()->where('device_id', $requester->deviceId), self::DAY, self::deviceMaxPerDay());
        }
    }

    /**
     * When this number may next be sent a code for this purpose, and which rule decides
     * it — the escalating wait, or the number's hourly count. `resend_in` on every send
     * is this, measured just after the send was written.
     *
     * @return array{0: CarbonInterface, 1: string}
     */
    public static function nextSendAt(string $phone, OtpPurpose $purpose, bool $owner = false): array
    {
        $at = now();
        $binding = '';

        $escalating = self::phoneSends($phone)
            ->where('purpose', $purpose->value)
            ->whereNull('settled_at')
            ->where('created_at', '>', now()->subSeconds(self::DAY))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'created_at']);

        if ($escalating->isNotEmpty()) {
            $cooldownEnds = $escalating->first()->created_at->copy()->addSeconds(self::cooldownAfter($escalating->count()));

            if ($cooldownEnds->greaterThan($at)) {
                [$at, $binding] = [$cooldownEnds, self::COOLDOWN];
            }
        }

        // The owner's hourly count is their own purpose only, so a stranger's forgotten-
        // password requests cannot fill it.
        $hourly = self::phoneSends($phone)->when($owner, fn (Builder $q) => $q->where('purpose', $purpose->value));
        $hourlyOpens = self::slotOpensAt($hourly, self::HOUR, self::maxPerHour());

        if ($hourlyOpens->greaterThan($at)) {
            [$at, $binding] = [$hourlyOpens, self::HOURLY];
        }

        return [$at, $binding];
    }

    /** Seconds until {@see self::nextSendAt()} — `0` when a code may go now. */
    public static function resendIn(string $phone, OtpPurpose $purpose, bool $owner = false): int
    {
        return self::when(self::nextSendAt($phone, $purpose, $owner)[0])['retry_after'];
    }

    public static function record(string $phone, OtpPurpose $purpose, string $kind, OtpRequester $requester): SmsSend
    {
        return SmsSend::query()->create([
            'phone' => $phone,
            'purpose' => $purpose->value,
            'kind' => $kind,
            'ip' => $requester->ip,
            'device_id' => $requester->deviceId,
        ]);
    }

    // ---------------------------------------------------------------- verification

    /** A correct code: the escalating wait for this number and purpose starts over. */
    public static function settle(string $phone, OtpPurpose $purpose): void
    {
        SmsSend::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('settled_at')
            ->update(['settled_at' => now()]);
    }

    /**
     * The latest code for this number and purpose was guessed dead. Counted against the
     * send that issued it; enough of them in 24 hours blocks the number.
     *
     * @return OtpBlock|null the block this burn caused, if it caused one
     */
    public static function burned(string $phone, OtpPurpose $purpose): ?OtpBlock
    {
        $issuedBy = SmsSend::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('burned_at')
            ->orderByDesc('id')
            ->first();

        $issuedBy?->forceFill(['burned_at' => now()])->save();

        $burned = self::phoneSends($phone)->where('burned_at', '>', now()->subSeconds(self::DAY))->count();

        if ($burned < self::maxFailedCodes() || self::activeBlock($phone) !== null) {
            return null;
        }

        return self::block($phone, OtpBlock::REASON_BURNED_CODES, now()->addHours(self::blockHours()));
    }

    // --------------------------------------------------------------------- refusals

    /**
     * `retry_after` in whole seconds, rounded up so a client that waits exactly that long
     * is never refused again for a fraction of a second, and `available_at` in UTC.
     *
     * @return array{retry_after: int, available_at: string}
     */
    public static function when(CarbonInterface $availableAt): array
    {
        $now = now();

        if (! $availableAt->greaterThan($now)) {
            return ['retry_after' => 0, 'available_at' => $now->copy()->utc()->toIso8601String()];
        }

        $seconds = (int) ceil(($availableAt->getPreciseTimestamp() - $now->getPreciseTimestamp()) / 1_000_000);

        return ['retry_after' => max(1, $seconds), 'available_at' => $availableAt->copy()->utc()->toIso8601String()];
    }

    /** @param  array<string, mixed>  $meta */
    public static function refusal(string $code, string $message, int $status, CarbonInterface $availableAt, array $meta = []): ApiException
    {
        return new ApiException($code, $message, $status, meta: self::when($availableAt) + $meta);
    }

    public static function rateLimited(CarbonInterface $availableAt): ApiException
    {
        return self::refusal('otp_send_rate_limited', 'محاولات كثيرة جدًا، حاول لاحقًا', 429, $availableAt);
    }

    public static function blocked(OtpBlock $block): ApiException
    {
        return self::refusal(
            'otp_phone_blocked',
            'تم إيقاف رموز التحقق لهذا الرقم مؤقتًا',
            423,
            $block->blocked_until,
            ['blocked_until' => $block->blocked_until->copy()->utc()->toIso8601String()],
        );
    }

    // ------------------------------------------------------------------------ counts

    /** The number's own sends — what an operator's unblock starts over. */
    private static function phoneSends(string $phone): Builder
    {
        return SmsSend::query()->where('phone', $phone)->whereNull('cleared_at');
    }

    /** @throws ApiException otp_send_rate_limited */
    private static function assertRoom(Builder $query, int $seconds, int $cap): void
    {
        $opens = self::slotOpensAt($query, $seconds, $cap);

        if ($opens->greaterThan(now())) {
            throw self::rateLimited($opens);
        }
    }

    /**
     * When a rolling window of `$seconds` that may hold `$cap` rows has room again: now,
     * or the moment the row that has to age out for there to be room does.
     */
    public static function slotOpensAt(Builder $query, int $seconds, int $cap): CarbonInterface
    {
        if ($cap <= 0) {
            return now()->addSeconds($seconds);
        }

        $since = now()->subSeconds($seconds);
        $count = (clone $query)->where('created_at', '>', $since)->count();

        if ($count < $cap) {
            return now();
        }

        $mustAge = (clone $query)
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->skip($count - $cap)
            ->first(['created_at']);

        return $mustAge === null ? now() : $mustAge->created_at->copy()->addSeconds($seconds);
    }
}
