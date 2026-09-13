<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * D-112 — «برقم الموبايل اللي مسجل بيه ودا الرقم الاساسي في اي عنوان هيتضاف طالما محطش رقم
 * تاني ولما ييجي يحط رقم تاني هيوديه لتفعيل الرقم ب sms والتفعيل دا بيتم التحكم فيه من
 * الداشبورد».
 *
 * An address's phone is the account's unless proven otherwise:
 *
 * - the account's own number — or no number — is stored as **null**, which resolves to
 *   the account's number at order time (D-83). A customer who changes their account
 *   number later carries it to every address that never named another;
 * - another number is refused outright while `addresses.alternate_phone_allowed` is off;
 * - and, while `addresses.alternate_phone_requires_otp` is on, refused until the account
 *   has proven it by SMS (`POST /addresses/phone/verify`). Once proven, it is the
 *   account's to reuse on any address.
 */
final class AddressContactPhone
{
    public const NOT_ALLOWED = 'لا يمكن استخدام رقم غير رقم حسابك في العنوان';

    public const NOT_VERIFIED = 'يلزم تأكيد هذا الرقم برسالة SMS قبل حفظه';

    public static function allowed(): bool
    {
        return SettingsCatalog::bool('addresses.alternate_phone_allowed');
    }

    public static function requiresOtp(): bool
    {
        return SettingsCatalog::bool('addresses.alternate_phone_requires_otp');
    }

    public static function isAccountPhone(User $user, ?string $phone): bool
    {
        return blank($phone) || $phone === $user->phone;
    }

    /** The account's own number, or one it has proven by SMS. */
    public static function isVerified(User $user, string $phone): bool
    {
        return self::isAccountPhone($user, $phone)
            || $user->verifiedPhones()->where('phone', $phone)->exists();
    }

    /**
     * What an address should store for the number the customer typed.
     *
     * @throws ApiException alternate_phone_not_allowed · phone_not_verified — both 422 on `contact_phone`
     */
    public static function resolve(User $user, ?string $phone): ?string
    {
        if (self::isAccountPhone($user, $phone)) {
            return null;
        }

        if (! self::allowed()) {
            throw self::notAllowed();
        }

        if (self::requiresOtp() && ! self::isVerified($user, $phone)) {
            throw new ApiException('phone_not_verified', self::NOT_VERIFIED, 422, errors: ['contact_phone' => [self::NOT_VERIFIED]]);
        }

        return $phone;
    }

    public static function notAllowed(): ApiException
    {
        return new ApiException('alternate_phone_not_allowed', self::NOT_ALLOWED, 422, errors: ['contact_phone' => [self::NOT_ALLOWED]]);
    }
}
