<?php

namespace App\Support;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Store-review access — the few phone numbers a Google Play or App Store reviewer signs
 * in with, and the one fixed code they are sent.
 *
 * A reviewer cannot receive an SMS: the numbers are ours, not theirs. The platform's answer
 * to that is **not** `otp.mode = test` — that would make every customer's code
 * predictable. It is a closed list in `.env` (`OTP_REVIEW_PHONES`, comma separated) and a
 * code beside it (`OTP_REVIEW_CODE`, six digits). A number on the list is answered with
 * that code and no gateway call — so ZADX is never billed for it and the send budget never
 * counts it (the row in `sms_sends` is `kind = review`). Every other number is untouched.
 *
 * What it never reaches, whatever the list says:
 *
 *   * a password change (D-107 — always a real SMS),
 *   * an address's second number (it proves a number the reviewer does not own),
 *   * any account that is an admin (D-107 — a code everybody knows must not land near the
 *     panel).
 *
 * A review account may browse, sign in and use every screen — and may never put a real job
 * into the platform: `refuseRealWork()` stops an order, a scheduled order, an errand and a
 * direct order at the action that would write it, so a real place is never rung, a real
 * captain never asked, and no basket is charged. The guard is keyed on the same list and
 * nothing else — every other account is untouched.
 *
 * `.env`, not the panel: the list is a production credential and must not be editable by
 * whoever holds a settings permission. Both keys empty is the default and means off.
 */
final class ReviewAccess
{
    /** @var list<OtpPurpose> */
    private const PURPOSES = [OtpPurpose::Login, OtpPurpose::Register, OtpPurpose::Reset, OtpPurpose::Verify];

    /** @return list<string> normalised, de-duplicated, only well-formed mobiles */
    public static function phones(): array
    {
        $raw = config('services.review.phones');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $phones = [];

        foreach (preg_split('/[,;\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
            $phone = PhoneNumber::normalise($one);

            if (preg_match(PhoneNumber::PATTERN, $phone) === 1) {
                $phones[$phone] = $phone;
            }
        }

        return array_values($phones);
    }

    /** The fixed code, or null when it is missing or not exactly six digits. */
    public static function code(): ?string
    {
        $code = trim((string) config('services.review.code'));

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : null;
    }

    /** Whether `$phone` is one of the review numbers — for code that must leave them out (dispatch). */
    public static function isReviewPhone(string $phone): bool
    {
        return in_array(PhoneNumber::normalise($phone), self::phones(), true);
    }

    /**
     * Leaves the review captain out of a dispatch pool: they may go online to see the
     * screen, and must never be handed a real customer's order.
     *
     * @param  Builder<Driver>  $drivers
     */
    public static function exceptFromDrivers($drivers): void
    {
        $phones = self::phones();

        if ($phones !== []) {
            $drivers->whereHas('user', fn ($q) => $q->whereNotIn('phone', $phones));
        }
    }

    /** Whether this account is one of the review accounts. */
    public static function isReviewAccount(?User $user): bool
    {
        return $user !== null && self::isReviewPhone((string) $user->phone);
    }

    /**
     * The one guard every job-creating action calls first. A review account gets a clear,
     * final answer instead of a real order; anybody else falls straight through.
     *
     * @throws ApiException 403 review_account_demo_only
     */
    public static function refuseRealWork(?User $user): void
    {
        if (self::isReviewAccount($user)) {
            Log::info('[review] a review account tried to create real work — refused, nothing was written.', [
                'user_id' => $user?->id,
            ]);

            throw new ApiException(
                'review_account_demo_only',
                'هذا حساب مراجعة تجريبي: يمكنك تصفّح التطبيق وتجربة الشاشات، لكن لا يمكن إرسال طلب أو مشوار فعلي منه.',
                403,
            );
        }
    }

    /** The fixed code for this send, or null when the send is an ordinary one. */
    public static function codeFor(string $phone, OtpPurpose $purpose, ?User $account = null): ?string
    {
        if (! in_array($purpose, self::PURPOSES, true) || $account?->isAdmin() === true) {
            return null;
        }

        $code = self::code();

        return $code !== null && self::isReviewPhone($phone) ? $code : null;
    }
}
