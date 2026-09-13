<?php

namespace App\Support;

use App\Models\UserAddress;

/**
 * D-111 — what an address still needs before an order can be sent to it.
 *
 * The pin and the area are always required, and the form never lets an address exist
 * without them: the area **is** the price (D-81) and the pin is where the captain goes.
 * Everything else is the operator's call — `addresses.required_fields` on `ADM-46` — so
 * a town where nobody knows their building number is not refused at checkout for it.
 *
 * An incomplete address may be **saved** (the first-launch address of D-110 usually has
 * no street), and may not be **ordered to**. `CheckoutPricer` turns this into the
 * `address_incomplete` blocker, with the missing fields named in Arabic, so the customer
 * is told what to add before the order is taken rather than the captain finding out at
 * the door.
 */
final class AddressCompleteness
{
    public const SETTING = 'addresses.required_fields';

    /** The fields an operator may make required, in the order the form draws them. */
    public const OPTIONAL_FIELDS = ['street', 'building', 'floor', 'apartment', 'landmark'];

    public const LABELS_AR = [
        'street' => 'الشارع',
        'building' => 'رقم العمارة',
        'floor' => 'الدور',
        'apartment' => 'رقم الشقة',
        'landmark' => 'علامة مميزة',
        'zone_id' => 'المنطقة',
        'location' => 'موقع الخريطة',
    ];

    public const LABELS_EN = [
        'street' => 'Street',
        'building' => 'Building',
        'floor' => 'Floor',
        'apartment' => 'Apartment',
        'landmark' => 'Landmark',
        'zone_id' => 'Area',
        'location' => 'Map pin',
    ];

    /** @return list<string> */
    public static function requiredFields(): array
    {
        $fields = SettingsCatalog::get(self::SETTING);

        return is_array($fields)
            ? array_values(array_intersect(self::OPTIONAL_FIELDS, $fields))
            : ['street'];
    }

    /**
     * @return list<array{field: string, label: string, label_en: string}>
     */
    public static function missing(UserAddress $address): array
    {
        $missing = [];

        if (! $address->service_zone_id) {
            $missing[] = 'zone_id';
        }

        $lat = (float) $address->latitude;
        $lng = (float) $address->longitude;

        if ($address->latitude === null || $address->longitude === null || ($lat === 0.0 && $lng === 0.0)) {
            $missing[] = 'location';
        }

        foreach (self::requiredFields() as $field) {
            if (blank($address->{$field})) {
                $missing[] = $field;
            }
        }

        return array_map(static fn (string $field) => [
            'field' => $field,
            'label' => self::LABELS_AR[$field],
            'label_en' => self::LABELS_EN[$field],
        ], $missing);
    }

    public static function isComplete(UserAddress $address): bool
    {
        return self::missing($address) === [];
    }

    /** «أكمل بيانات العنوان قبل تأكيد الطلب: الشارع، رقم العمارة». */
    public static function sentence(array $missing): string
    {
        return 'أكمل بيانات العنوان قبل تأكيد الطلب: '.implode('، ', array_column($missing, 'label'));
    }
}
