<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserAddress;

/**
 * D-112 — the address a checkout names, or a sentence the customer can act on.
 *
 * Quote, order and scheduled order each ran `findOrFail` scoped to the caller, so an
 * address the customer had deleted — or an id from another account — answered **404**,
 * which every client renders as «المسار غير موجود»: the screen reads as broken rather
 * than as «pick another address». It is a 422 on `address_id` now, in words.
 */
final class CheckoutAddress
{
    public const MESSAGE = 'العنوان غير موجود — اختر عنوانًا من عناوينك';

    /**
     * @param  list<string>  $with
     *
     * @throws ApiException address_not_found 422
     */
    public static function find(User $user, mixed $id, array $with = []): UserAddress
    {
        $address = is_numeric($id)
            ? UserAddress::query()->where('user_id', $user->id)->with($with)->find((int) $id)
            : null;

        if ($address === null) {
            throw new ApiException('address_not_found', self::MESSAGE, 422, errors: ['address_id' => [self::MESSAGE]]);
        }

        return $address;
    }
}
