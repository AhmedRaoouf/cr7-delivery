<?php

namespace App\Support;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The one check every verification code goes through — sign-in, registration, password
 * reset, password change and an address's second number.
 *
 * It was written out four times, each inside the same `DB::transaction` that then threw
 * on a wrong code. The rollback undid the `increment('attempts')` along with everything
 * else, so a wrong code cost nothing, `otp_attempts_exceeded` could never fire, and a
 * six-digit code had no ceiling on guesses at all. The check now commits its own
 * transaction before it throws, which is why it **must not be called inside another
 * one**: an outer rollback would undo the attempt again.
 *
 * `otp.max_attempts` is the operator's (D-106); it was a hard-coded 5.
 *
 * Batch 68:
 *
 *   * **Only the latest code counts.** The newest row for the number and purpose is the
 *     only one checked; once it is spent, an older one that happened to be unspent does
 *     not come back to life.
 *   * **A blocked number checks nothing** — `423 otp_phone_blocked`.
 *   * **The guess that uses the last attempt answers `423 otp_attempts_exceeded`**, not a
 *     `422` with `attempts_left: 0`: the code is dead at that moment, and the answer says
 *     how long until a new one may be asked for (`retry_after`, `0` = now). That code is
 *     counted as burned; `otp.max_failed_codes` of them in 24 hours block the number.
 *   * **A correct code starts the escalating resend wait over** for that number and purpose.
 */
final class OtpVerifier
{
    /**
     * The latest code this phone was sent for this purpose, checked and spent.
     *
     * @param  ApiException|null  $whenNone  what to say when no code was ever sent — by
     *                                       default the same «رمز التحقق غير صحيح» as a
     *                                       wrong one, so the endpoint cannot be used to
     *                                       ask which numbers hold a code.
     * @param  bool  $owner  a signed-in admin's own password-change code — not stopped by a
     *                       block a stranger could have caused (see {@see OtpGuard})
     *
     * @throws ApiException otp_invalid 422 · otp_expired 422 · otp_attempts_exceeded 423 · otp_phone_blocked 423
     */
    public static function consume(string $phone, OtpPurpose $purpose, string $code, ?ApiException $whenNone = null, bool $owner = false): OtpCode
    {
        if (! $owner) {
            OtpGuard::assertNotBlocked($phone);
        }

        $max = OtpGuard::maxAttempts();

        /** @var array{0: string, 1: OtpCode|null, 2: int} $outcome */
        $outcome = DB::transaction(function () use ($phone, $purpose, $code, $max): array {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->consumed_at !== null) {
                return ['none', null, 0];
            }

            if ($otp->expires_at->isPast()) {
                return ['expired', null, 0];
            }

            if ($otp->attempts >= $max) {
                return ['exceeded', null, 0];
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return $otp->attempts >= $max
                    ? ['burned', null, 0]
                    : ['invalid', null, $max - $otp->attempts];
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            return ['ok', $otp, 0];
        });

        [$result, $otp, $attemptsLeft] = $outcome;

        if ($result === 'ok') {
            OtpGuard::settle($phone, $purpose);

            return $otp;
        }

        $resend = fn (): array => OtpGuard::when(OtpGuard::nextSendAt($phone, $purpose, $owner)[0]);

        throw match ($result) {
            'none' => $whenNone ?? self::invalid(),
            'invalid' => self::invalid(['attempts_left' => $attemptsLeft]),
            'expired' => new ApiException('otp_expired', 'انتهت صلاحية رمز التحقق', 422, meta: $resend()),
            'burned' => self::burned($phone, $purpose, $resend),
            default => self::exceeded($resend()),
        };
    }

    /** The guess that used the last attempt: the code is burned, and perhaps the number with it. */
    private static function burned(string $phone, OtpPurpose $purpose, \Closure $resend): ApiException
    {
        $block = OtpGuard::burned($phone, $purpose);

        return $block !== null ? OtpGuard::blocked($block) : self::exceeded($resend());
    }

    /** @param  array{retry_after: int, available_at: string}  $when */
    private static function exceeded(array $when): ApiException
    {
        return new ApiException('otp_attempts_exceeded', 'تم تجاوز عدد المحاولات المسموح بها', 423, meta: ['attempts_left' => 0] + $when);
    }

    /** @param  array<string, mixed>  $meta */
    private static function invalid(array $meta = []): ApiException
    {
        return new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422, meta: $meta);
    }
}
