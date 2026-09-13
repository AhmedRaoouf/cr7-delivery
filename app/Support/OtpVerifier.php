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
 */
final class OtpVerifier
{
    /**
     * The latest live code this phone was sent for this purpose, checked and spent.
     *
     * @param  ApiException|null  $whenNone  what to say when no code was ever sent — by
     *                                       default the same «رمز التحقق غير صحيح» as a
     *                                       wrong one, so the endpoint cannot be used to
     *                                       ask which numbers hold a code.
     *
     * @throws ApiException otp_invalid 422 · otp_expired 422 · otp_attempts_exceeded 423
     */
    public static function consume(string $phone, OtpPurpose $purpose, string $code, ?ApiException $whenNone = null): OtpCode
    {
        $max = max(1, SettingsCatalog::int('otp.max_attempts'));

        [$otp, $failure] = DB::transaction(function () use ($phone, $purpose, $code, $whenNone, $max): array {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return [null, $whenNone ?? self::invalid()];
            }

            if ($otp->expires_at->isPast()) {
                return [null, new ApiException('otp_expired', 'انتهت صلاحية رمز التحقق', 422)];
            }

            if ($otp->attempts >= $max) {
                return [null, new ApiException('otp_attempts_exceeded', 'تم تجاوز عدد المحاولات المسموح بها', 423)];
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return [null, self::invalid(['attempts_left' => max(0, $max - $otp->attempts)])];
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            return [$otp, null];
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $otp;
    }

    /** @param  array<string, mixed>  $meta */
    private static function invalid(array $meta = []): ApiException
    {
        return new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422, meta: $meta);
    }
}
