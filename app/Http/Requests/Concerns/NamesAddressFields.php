<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * D-82 — an address that is refused says which field, and says it in Arabic.
 *
 * Operator, 2026-09-11: «لما يعمل خطأ في اضافه العنوان يقلي اي بالظبط الغلط او
 * عايز حقل اي تاني». The rules were there; the sentences were not, so a missing
 * area came back as Laravel's «The zone id field is required.» and the app —
 * which reads `messageKey` and not `errors` — showed it in a grey toast with
 * nothing marked on the form.
 *
 * The normalisation half matters as much as the words. A text field the
 * customer skipped arrives as `''`, not as `null`: `nullable` does not catch
 * it, so `''` was stored, and `$address->contact_phone ?? $user->phone` then
 * returned the empty string rather than the account's number (D-83).
 */
trait NamesAddressFields
{
    /** @var list<string> */
    private array $optionalText = [
        'title', 'street', 'building', 'floor', 'apartment', 'landmark', 'contact_phone',
    ];

    protected function prepareForValidation(): void
    {
        $patch = [];

        foreach ($this->optionalText as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $value = is_string($value) ? trim($value) : $value;
            $patch[$field] = ($value === '' || $value === null) ? null : $value;
        }

        // Stored as the eleven digits, whatever the customer pasted: the
        // captain's screen turns this into a `tel:` link, and «+20 109 000 9599»
        // is a number a dialler opens with three separators in it.
        if (filled($patch['contact_phone'] ?? null)) {
            $patch['contact_phone'] = PhoneNumber::normalise((string) $patch['contact_phone']);
        }

        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'اختر نوع العنوان: المنزل أو العمل أو غير ذلك.',
            'label.in' => 'اختر نوع العنوان: المنزل أو العمل أو غير ذلك.',
            'title.required' => 'اكتب اسمًا لهذا العنوان.',
            'title.required_if' => 'اكتب اسمًا لهذا العنوان.',
            'city_id.required' => 'اختر المدينة.',
            'city_id.exists' => 'المدينة المختارة غير متاحة.',
            'zone_id.required' => 'اختر المنطقة — سعر التوصيل يتحدد على أساسها.',
            'zone_id.exists' => 'المنطقة المختارة غير متاحة.',
            'street.required' => 'اكتب اسم الشارع.',
            'latitude.required' => 'حدد موقع العنوان على الخريطة.',
            'longitude.required' => 'حدد موقع العنوان على الخريطة.',
            'latitude.numeric' => 'حدد موقع العنوان على الخريطة.',
            'longitude.numeric' => 'حدد موقع العنوان على الخريطة.',
            'contact_phone.regex' => 'أدخل رقم هاتف مصري صحيح مكونًا من ‪11‬ رقمًا يبدأ بـ ‪01‬.',
            'contact_phone.string' => 'أدخل رقم هاتف مصري صحيح مكونًا من ‪11‬ رقمًا يبدأ بـ ‪01‬.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'label' => 'نوع العنوان',
            'title' => 'اسم العنوان',
            'city_id' => 'المدينة',
            'zone_id' => 'المنطقة',
            'street' => 'الشارع',
            'building' => 'المبنى',
            'floor' => 'الدور',
            'apartment' => 'الشقة',
            'landmark' => 'علامة مميزة',
            'contact_phone' => 'رقم التواصل',
            'latitude' => 'الموقع على الخريطة',
            'longitude' => 'الموقع على الخريطة',
            'is_default' => 'العنوان الافتراضي',
        ];
    }
}
