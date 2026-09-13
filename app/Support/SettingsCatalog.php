<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Setting;

/**
 * D-113 — every key an operator may edit, declared once.
 *
 * `ADM-46` on the web and `ADM-47` on the phone each kept their own list of keys, and
 * the two lists disagreed with each other and with the table: the web form named five
 * keys the table has never held, and it posted them under a `settings` envelope that
 * `SettingController` never opened — so nothing it sent was written, under a success
 * toast. Both clients now render the panel from {@see schema()}, and a `PATCH` is checked
 * against the same declaration, so there is one list and it is this one.
 *
 * A field is:
 *
 *     key · type · default · label_ar · label_en
 *     [min · max · step · suffix_ar · suffix_en · helper_ar · helper_en · warning_ar
 *      · warning_en · options · nullable · public]
 *
 * `type` is one of `int`, `decimal`, `bool`, `string`, `phone`, `email`, `digits`,
 * `enum`, `multi`, `version`. `public` means `GET /config` may publish it.
 */
final class SettingsCatalog
{
    /** @return list<array{key: string, title_ar: string, title_en: string, fields: list<array<string, mixed>>}> */
    public static function groups(): array
    {
        return [
            [
                'key' => 'delivery',
                'title_ar' => 'التوصيل والعمولة',
                'title_en' => 'Delivery and commission',
                'fields' => [
                    [
                        'key' => 'delivery.platform_commission_rate', 'type' => 'int', 'default' => 15,
                        'min' => 0, 'max' => 100, 'suffix_ar' => '%', 'suffix_en' => '%',
                        'label_ar' => 'نسبة المنصة من رسوم التوصيل', 'label_en' => 'Platform share of the delivery fee',
                        'helper_ar' => 'نسبة الكابتن هي الباقي من ‪100‬%.', 'helper_en' => 'The captain keeps the rest of 100%.',
                    ],
                    [
                        'key' => 'delivery.extra_stop_fee', 'type' => 'decimal', 'default' => 10, 'min' => 0, 'max' => 500,
                        'suffix_ar' => 'ج.م', 'suffix_en' => 'EGP',
                        'label_ar' => 'رسوم كل مكان إضافي في السلة', 'label_en' => 'Fee per extra place in the basket',
                        'helper_ar' => 'تُضاف إلى سعر منطقة العميل لكل مكان بعد الأول. صفر يلغيها.',
                        'helper_en' => "Added to the customer's area price for every place after the first. Zero turns it off.",
                    ],
                    [
                        'key' => 'delivery.first_order_free', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'توصيل مجاني لأول طلب للعميل', 'label_en' => "Free delivery on a customer's first order",
                        'helper_ar' => 'لا يحصل الكابتن ولا المنصة على شيء من رسوم هذا الطلب.',
                        'helper_en' => 'Neither the captain nor the platform earns anything on that fee.',
                    ],
                    [
                        'key' => 'platform.order_fee', 'type' => 'decimal', 'default' => 0, 'min' => 0, 'max' => 100,
                        'suffix_ar' => 'ج.م', 'suffix_en' => 'EGP', 'public' => true,
                        'label_ar' => 'رسوم الخدمة لكل طلب', 'label_en' => 'Service fee per order',
                        'helper_ar' => 'صفر يخفي السطر من كل الشاشات.', 'helper_en' => 'Zero hides the line on every screen.',
                    ],
                    [
                        'key' => 'platform.order_fee_applies_to_direct', 'type' => 'bool', 'default' => true,
                        'label_ar' => 'رسوم الخدمة على الطلبات المباشرة', 'label_en' => 'Service fee on direct orders',
                    ],
                    [
                        'key' => 'orders.late_cancellation_fee', 'type' => 'decimal', 'default' => 20, 'min' => 0, 'max' => 500,
                        'suffix_ar' => 'ج.م', 'suffix_en' => 'EGP',
                        'label_ar' => 'رسوم الإلغاء بعد بدء التحضير', 'label_en' => 'Fee for cancelling after preparation starts',
                    ],
                ],
            ],
            [
                'key' => 'cart',
                'title_ar' => 'السلة',
                'title_en' => 'Basket',
                'fields' => [
                    [
                        'key' => 'cart.max_restaurants', 'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 5, 'public' => true,
                        'label_ar' => 'أقصى عدد أماكن في الطلب الواحد', 'label_en' => 'Most places in one order',
                    ],
                    [
                        'key' => 'cart.max_qty_per_line', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50, 'public' => true,
                        'label_ar' => 'أقصى كمية للصنف الواحد', 'label_en' => 'Most of one item',
                    ],
                ],
            ],
            [
                'key' => 'payment',
                'title_ar' => 'طرق الدفع',
                'title_en' => 'Payment methods',
                'fields' => [
                    ['key' => 'payment.cash_enabled', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'الدفع عند الاستلام', 'label_en' => 'Cash on delivery'],
                    ['key' => 'payment.vodafone_cash_enabled', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'فودافون كاش', 'label_en' => 'Vodafone Cash'],
                    ['key' => 'payment.vodafone_cash_number', 'type' => 'phone', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'رقم فودافون كاش', 'label_en' => 'Vodafone Cash number'],
                    ['key' => 'payment.vodafone_cash_number_alt', 'type' => 'phone', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'رقم فودافون كاش الثاني', 'label_en' => 'Second Vodafone Cash number'],
                    ['key' => 'payment.instapay_enabled', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'إنستا باي', 'label_en' => 'InstaPay'],
                    ['key' => 'payment.instapay_handle', 'type' => 'string', 'default' => null, 'nullable' => true, 'max' => 100, 'public' => true,
                        'label_ar' => 'عنوان أو رقم إنستا باي', 'label_en' => 'InstaPay address or number'],
                    ['key' => 'payment.transfer_deadline_minutes', 'type' => 'int', 'default' => 15, 'min' => 5, 'max' => 120, 'public' => true,
                        'suffix_ar' => 'دقيقة', 'suffix_en' => 'min',
                        'label_ar' => 'مهلة التحويل', 'label_en' => 'Transfer deadline'],
                    ['key' => 'payment.reminder_before_minutes', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 60, 'public' => true,
                        'suffix_ar' => 'دقيقة', 'suffix_en' => 'min',
                        'label_ar' => 'تذكير قبل انتهاء مهلة التحويل', 'label_en' => 'Reminder before the transfer deadline'],
                ],
            ],
            [
                'key' => 'ring',
                'title_ar' => 'رنين الطلب الجديد',
                'title_en' => 'New-order ring',
                'fields' => [
                    ['key' => 'ring.enabled', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'رنين الطلب الجديد على هاتف المكان', 'label_en' => "Ring the place's phone for a new order",
                        'helper_ar' => 'يرن كالمكالمة حتى والتطبيق مغلق.', 'helper_en' => 'Rings like a call, even with the app closed.'],
                    ['key' => 'ring.duration_seconds', 'type' => 'int', 'default' => 30, 'min' => 10, 'max' => 60, 'public' => true,
                        'suffix_ar' => 'ثانية', 'suffix_en' => 's', 'label_ar' => 'مدة الرنة', 'label_en' => 'Ring length'],
                    ['key' => 'ring.gap_seconds', 'type' => 'int', 'default' => 60, 'min' => 15, 'max' => 600, 'public' => true,
                        'suffix_ar' => 'ثانية', 'suffix_en' => 's', 'label_ar' => 'الانتظار بين الرنات', 'label_en' => 'Wait between rings',
                        'helper_ar' => 'تُحسب من نهاية الرنة السابقة.', 'helper_en' => 'Counted from the end of the previous ring.'],
                    ['key' => 'ring.max_attempts', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10, 'public' => true,
                        'label_ar' => 'عدد مرات الرنين إن لم يرد المكان', 'label_en' => 'Rings when the place does not answer'],
                ],
            ],
            [
                'key' => 'timeouts',
                'title_ar' => 'المهل الزمنية',
                'title_en' => 'Timeouts',
                'fields' => [
                    ['key' => 'restaurant.accept_timeout_minutes', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 60,
                        'suffix_ar' => 'دقيقة', 'suffix_en' => 'min',
                        'label_ar' => 'مهلة رد المكان على الطلب', 'label_en' => 'Time for the place to answer'],
                    ['key' => 'order.customer_confirm_timeout_minutes', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 60,
                        'suffix_ar' => 'دقيقة', 'suffix_en' => 'min',
                        'label_ar' => 'مهلة تأكيد العميل', 'label_en' => 'Time for the customer to confirm'],
                    ['key' => 'dispatch.rebroadcast_interval_seconds', 'type' => 'int', 'default' => 120, 'min' => 30, 'max' => 900,
                        'suffix_ar' => 'ثانية', 'suffix_en' => 's',
                        'label_ar' => 'إعادة عرض الطلب على كل الكباتن كل', 'label_en' => 'Offer the order to every captain again every'],
                    ['key' => 'dispatch.escalate_after_seconds', 'type' => 'int', 'default' => 120, 'min' => 30, 'max' => 3600,
                        'suffix_ar' => 'ثانية', 'suffix_en' => 's',
                        'label_ar' => 'تنبيه الإدارة إن لم يقبل أي كابتن بعد', 'label_en' => 'Alert the office when no captain has accepted after'],
                ],
            ],
            [
                'key' => 'codes',
                'title_ar' => 'أكواد الطلب',
                'title_en' => 'Order codes',
                'fields' => [
                    ['key' => 'orders.pickup_code_required', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'كود الاستلام من المكان', 'label_en' => 'Pickup code at the place',
                        'helper_ar' => 'يعطيه المكان للكابتن عند تسليم الطلب. عند إيقافه يؤكد الكابتن الاستلام بزر واحد.',
                        'helper_en' => 'The place gives it to the captain at handover. Off, the captain confirms pickup with one button.'],
                    ['key' => 'orders.delivery_code_required', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'كود التسليم للعميل', 'label_en' => 'Delivery code at the door',
                        'helper_ar' => 'يعطيه العميل للكابتن عند الاستلام. عند إيقافه يؤكد الكابتن التسليم بزر واحد.',
                        'helper_en' => 'The customer gives it to the captain at the door. Off, the captain confirms with one button.'],
                    ['key' => 'order.otp_max_attempts', 'type' => 'int', 'default' => 5, 'min' => 3, 'max' => 10,
                        'label_ar' => 'المحاولات الخاطئة قبل قفل الكود', 'label_en' => 'Wrong tries before a code locks'],
                    ['key' => 'order.delivery_otp_resend_max', 'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 10,
                        'label_ar' => 'مرات إعادة إرسال كود التسليم', 'label_en' => 'Times the delivery code can be resent'],
                ],
            ],
            [
                'key' => 'otp',
                'title_ar' => 'التحقق والرسائل',
                'title_en' => 'Verification and SMS',
                'fields' => [
                    [
                        'key' => 'otp.mode', 'type' => 'enum', 'default' => 'sms',
                        'label_ar' => 'رموز التحقق', 'label_en' => 'Verification codes',
                        'options' => [
                            ['value' => 'sms', 'label_ar' => 'رسالة SMS برمز عشوائي', 'label_en' => 'SMS with a random code'],
                            ['value' => 'test', 'label_ar' => 'وضع التجربة — رمز ثابت بلا رسائل', 'label_en' => 'Test mode — one fixed code, no SMS'],
                        ],
                        'warning_ar' => 'في وضع التجربة يستطيع أي شخص يعرف الرمز إنشاء حساب أو استعادة كلمة مرور أي عميل أو كابتن أو مكان. لا يسري على كلمة مرور الإدارة.',
                        'warning_en' => "In test mode anyone who knows the code can sign up or reset any customer's, captain's or place's password. It never applies to an admin's password.",
                    ],
                    ['key' => 'otp.test_code', 'type' => 'digits', 'default' => '123456', 'min' => 6, 'max' => 6,
                        'label_ar' => 'رمز وضع التجربة', 'label_en' => 'Test-mode code'],
                    ['key' => 'auth.register_requires_otp', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'التحقق من رقم الهاتف عند إنشاء حساب', 'label_en' => 'Verify the phone number at sign-up',
                        'helper_ar' => 'عند إيقافه ينشئ العميل حسابه بالرقم والاسم وكلمة المرور دون رمز.',
                        'helper_en' => 'Off, a customer signs up with a number, a name and a password and no code.'],
                    ['key' => 'otp.ttl_minutes', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 30,
                        'suffix_ar' => 'دقيقة', 'suffix_en' => 'min', 'label_ar' => 'صلاحية الرمز', 'label_en' => 'Code lifetime'],
                    ['key' => 'otp.resend_seconds', 'type' => 'int', 'default' => 60, 'min' => 30, 'max' => 600,
                        'suffix_ar' => 'ثانية', 'suffix_en' => 's', 'label_ar' => 'الانتظار قبل إعادة الإرسال', 'label_en' => 'Wait before resending'],
                    ['key' => 'otp.max_attempts', 'type' => 'int', 'default' => 5, 'min' => 3, 'max' => 10,
                        'label_ar' => 'المحاولات الخاطئة قبل قفل الرمز', 'label_en' => 'Wrong tries before a code locks'],
                    ['key' => 'otp.max_per_hour', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20,
                        'label_ar' => 'أقصى رسائل للرقم الواحد في الساعة', 'label_en' => 'Most messages to one number per hour'],
                ],
            ],
            [
                'key' => 'addresses',
                'title_ar' => 'العناوين',
                'title_en' => 'Addresses',
                'fields' => [
                    [
                        'key' => 'addresses.required_fields', 'type' => 'multi', 'default' => ['street'], 'public' => true,
                        'label_ar' => 'الحقول المطلوبة في العنوان', 'label_en' => 'Required address fields',
                        'helper_ar' => 'المنطقة وموقع الخريطة مطلوبان دائمًا.', 'helper_en' => 'The area and the map pin are always required.',
                        'options' => array_map(
                            static fn (string $field) => ['value' => $field, 'label_ar' => AddressCompleteness::LABELS_AR[$field], 'label_en' => AddressCompleteness::LABELS_EN[$field]],
                            AddressCompleteness::OPTIONAL_FIELDS,
                        ),
                    ],
                    ['key' => 'addresses.alternate_phone_allowed', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'السماح برقم تواصل غير رقم الحساب', 'label_en' => "Allow a contact number other than the account's"],
                    ['key' => 'addresses.alternate_phone_requires_otp', 'type' => 'bool', 'default' => true, 'public' => true,
                        'label_ar' => 'تأكيد رقم التواصل الآخر برسالة SMS', 'label_en' => 'Verify another contact number by SMS'],
                ],
            ],
            [
                'key' => 'direct',
                'title_ar' => 'الطلبات المباشرة',
                'title_en' => 'Direct orders',
                'fields' => [
                    ['key' => 'direct_orders.enabled', 'type' => 'bool', 'default' => true,
                        'label_ar' => 'السماح بالطلبات المباشرة', 'label_en' => 'Allow direct orders'],
                    ['key' => 'direct_orders.daily_cap_per_restaurant', 'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 500,
                        'label_ar' => 'الحد اليومي لكل مكان', 'label_en' => 'Daily cap per place'],
                    ['key' => 'direct_orders.ratio_alert_threshold', 'type' => 'decimal', 'default' => 0.4, 'min' => 0, 'max' => 1, 'step' => 0.05,
                        'label_ar' => 'تنبيه عند نسبة الطلبات المباشرة', 'label_en' => 'Alert at a direct-order share of',
                        'helper_ar' => '‪0.40‬ = ‪40‬% من طلبات المكان خلال ‪7‬ أيام.', 'helper_en' => "0.40 = 40% of the place's orders over 7 days."],
                ],
            ],
            [
                'key' => 'support',
                'title_ar' => 'الدعم والتطبيق',
                'title_en' => 'Support and app',
                'fields' => [
                    ['key' => 'support.phone', 'type' => 'phone', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'رقم الدعم', 'label_en' => 'Support phone'],
                    ['key' => 'support.phone_alt', 'type' => 'phone', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'رقم الدعم الثاني', 'label_en' => 'Second support phone'],
                    ['key' => 'support.whatsapp', 'type' => 'phone', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'واتساب الدعم', 'label_en' => 'Support WhatsApp'],
                    ['key' => 'support.email', 'type' => 'email', 'default' => null, 'nullable' => true, 'public' => true,
                        'label_ar' => 'بريد الدعم', 'label_en' => 'Support email'],
                    ['key' => 'support.hours_note', 'type' => 'string', 'default' => null, 'nullable' => true, 'max' => 120, 'public' => true,
                        'label_ar' => 'مواعيد الدعم', 'label_en' => 'Support hours'],
                    ['key' => 'app.force_update.android_min_version', 'type' => 'version', 'default' => '1.0.0', 'public' => true,
                        'label_ar' => 'أقل إصدار مسموح — أندرويد', 'label_en' => 'Minimum version — Android'],
                    ['key' => 'app.force_update.ios_min_version', 'type' => 'version', 'default' => '1.0.0', 'public' => true,
                        'label_ar' => 'أقل إصدار مسموح — iOS', 'label_en' => 'Minimum version — iOS'],
                    ['key' => 'app.maintenance_mode', 'type' => 'bool', 'default' => false, 'public' => true,
                        'label_ar' => 'وضع الصيانة', 'label_en' => 'Maintenance mode',
                        'warning_ar' => 'يوقف التطبيق لكل العملاء والكباتن حتى يُطفأ.', 'warning_en' => 'Stops the app for every customer and captain until it is switched off.'],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> keyed by setting key */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field + ['group' => $group['key']];
            }
        }

        return $fields;
    }

    /** @return array<string, mixed>|null */
    public static function field(string $key): ?array
    {
        return self::fields()[$key] ?? null;
    }

    /** The declared default — what a setting reads when nobody has written it. */
    public static function default(string $key): mixed
    {
        return self::field($key)['default'] ?? null;
    }

    /**
     * The typed value of a declared key: the stored one when there is one, the declared
     * default otherwise. Every reader of a catalogue key goes through here, so a stored
     * `"30"` and a stored `30` read the same and a missing row reads the default rather
     * than null.
     */
    public static function get(string $key): mixed
    {
        $field = self::field($key);
        $raw = Settings::get($key);

        if ($field === null) {
            return $raw;
        }

        if ($raw === null) {
            return $field['default'] ?? null;
        }

        try {
            return self::normalize($field, $raw);
        } catch (ApiException) {
            // A value somebody wrote by hand that the catalogue would refuse reads as
            // the default rather than breaking every request that touches it.
            return $field['default'] ?? null;
        }
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function float(string $key): float
    {
        return (float) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /**
     * The panel both clients draw: every group, every field, and its current value.
     *
     * @return list<array<string, mixed>>
     */
    public static function schema(): array
    {
        $stored = Setting::query()->pluck('value', 'key');

        return array_map(static function (array $group) use ($stored) {
            $group['fields'] = array_map(static function (array $field) use ($stored) {
                $value = $stored->has($field['key']) ? $stored->get($field['key']) : ($field['default'] ?? null);

                try {
                    $value = $value === null ? null : self::normalize($field, $value);
                } catch (ApiException) {
                    $value = $field['default'] ?? null;
                }

                return $field + ['value' => $value];
            }, $group['fields']);

            return $group;
        }, self::groups());
    }

    /**
     * Checks a whole edit before any of it is written.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the values, typed
     *
     * @throws ApiException 422 naming every refused key
     */
    public static function validate(array $values): array
    {
        $errors = [];
        $clean = [];

        foreach ($values as $key => $value) {
            $field = self::field((string) $key);

            if ($field === null) {
                $errors[$key] = ['هذا الإعداد غير قابل للتعديل من لوحة التحكم'];

                continue;
            }

            try {
                $clean[$key] = self::normalize($field, $value);
            } catch (ApiException $e) {
                $errors[$key] = [$e->getMessage()];
            }
        }

        // Cross-field rules — each is a sentence an operator would otherwise learn from a
        // customer who could not pay.
        $after = fn (string $key) => array_key_exists($key, $clean) ? $clean[$key] : self::get($key);

        // Checked only when the edit touches one of the keys a rule reads, so a server that
        // already holds an awkward pair can still save an unrelated field.
        $touches = fn (string ...$keys) => array_intersect($keys, array_keys($clean)) !== [];

        if ($errors === []) {
            $methods = ['payment.cash_enabled', 'payment.vodafone_cash_enabled', 'payment.instapay_enabled'];

            if ($touches(...$methods) && ! collect($methods)->contains(fn ($k) => (bool) $after($k))) {
                $errors['payment.cash_enabled'] = ['يجب أن تبقى طريقة دفع واحدة على الأقل مفعّلة'];
            }

            if ($touches('payment.vodafone_cash_enabled', 'payment.vodafone_cash_number')
                && (bool) $after('payment.vodafone_cash_enabled') && blank($after('payment.vodafone_cash_number'))) {
                $errors['payment.vodafone_cash_number'] = ['أدخل رقم فودافون كاش أو أوقف هذه الطريقة'];
            }

            if ($touches('payment.instapay_enabled', 'payment.instapay_handle')
                && (bool) $after('payment.instapay_enabled') && blank($after('payment.instapay_handle'))) {
                $errors['payment.instapay_handle'] = ['أدخل عنوان إنستا باي أو أوقف هذه الطريقة'];
            }

            if ($touches('payment.reminder_before_minutes', 'payment.transfer_deadline_minutes')
                && (int) $after('payment.reminder_before_minutes') >= (int) $after('payment.transfer_deadline_minutes')) {
                $errors['payment.reminder_before_minutes'] = ['يجب أن يأتي التذكير قبل انتهاء مهلة التحويل'];
            }
        }

        if ($errors !== []) {
            throw new ApiException('validation_failed', 'تعذّر حفظ بعض الإعدادات', 422, errors: $errors);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     *
     * @throws ApiException
     */
    public static function normalize(array $field, mixed $value): mixed
    {
        $nullable = (bool) ($field['nullable'] ?? false);

        if ($value === null || (is_string($value) && trim($value) === '' && $field['type'] !== 'multi')) {
            if ($nullable) {
                return null;
            }

            throw new ApiException('validation_failed', 'هذه القيمة مطلوبة', 422);
        }

        return match ($field['type']) {
            'int' => self::number($field, $value, true),
            'decimal' => self::number($field, $value, false),
            'bool' => self::boolean($value),
            'string' => self::text($field, $value),
            'phone' => self::phone($value),
            'email' => self::email($value),
            'digits' => self::digits($field, $value),
            'version' => self::version($value),
            'enum' => self::choice($field, $value),
            'multi' => self::choices($field, $value),
            default => $value,
        };
    }

    private static function number(array $field, mixed $value, bool $integer): int|float
    {
        if (! is_numeric($value)) {
            throw new ApiException('validation_failed', 'أدخل رقمًا', 422);
        }

        $number = $integer ? (int) round((float) $value) : round((float) $value, 2);

        if ($integer && (float) $value != $number) {
            throw new ApiException('validation_failed', 'أدخل رقمًا صحيحًا بلا كسور', 422);
        }

        if (isset($field['min']) && $number < $field['min']) {
            throw new ApiException('validation_failed', 'أقل قيمة مسموحة ‪'.$field['min'].'‬', 422);
        }

        if (isset($field['max']) && $number > $field['max']) {
            throw new ApiException('validation_failed', 'أكبر قيمة مسموحة ‪'.$field['max'].'‬', 422);
        }

        return $number;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new ApiException('validation_failed', 'القيمة يجب أن تكون تشغيل أو إيقاف', 422);
        }

        return $parsed;
    }

    private static function text(array $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new ApiException('validation_failed', 'أدخل نصًا', 422);
        }

        $text = trim((string) $value);

        if (isset($field['max']) && mb_strlen($text) > $field['max']) {
            throw new ApiException('validation_failed', 'النص أطول من ‪'.$field['max'].'‬ حرفًا', 422);
        }

        return $text;
    }

    private static function phone(mixed $value): string
    {
        $phone = PhoneNumber::normalise((string) $value);

        if (! preg_match(PhoneNumber::PATTERN, $phone)) {
            throw new ApiException('validation_failed', 'أدخل رقم هاتف مصريًا صحيحًا من ‪11‬ رقمًا', 422);
        }

        return $phone;
    }

    private static function email(mixed $value): string
    {
        $email = trim((string) $value);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('validation_failed', 'أدخل بريدًا إلكترونيًا صحيحًا', 422);
        }

        return $email;
    }

    private static function digits(array $field, mixed $value): string
    {
        $digits = trim((string) $value);
        $length = (int) ($field['max'] ?? 6);

        if (! preg_match('/^\d{'.$length.'}$/', $digits)) {
            throw new ApiException('validation_failed', 'أدخل ‪'.$length.'‬ أرقام', 422);
        }

        return $digits;
    }

    private static function version(mixed $value): string
    {
        $version = trim((string) $value);

        if (! preg_match('/^\d+(\.\d+){0,3}$/', $version)) {
            throw new ApiException('validation_failed', 'أدخل رقم إصدار مثل ‪1.0.2‬', 422);
        }

        return $version;
    }

    private static function choice(array $field, mixed $value): string
    {
        $allowed = array_column($field['options'] ?? [], 'value');

        if (! in_array((string) $value, $allowed, true)) {
            throw new ApiException('validation_failed', 'اختر قيمة من القائمة', 422);
        }

        return (string) $value;
    }

    /** @return list<string> */
    private static function choices(array $field, mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : array_map('trim', explode(',', $value));
        }

        if (! is_array($value)) {
            throw new ApiException('validation_failed', 'اختر من القائمة', 422);
        }

        $allowed = array_column($field['options'] ?? [], 'value');
        $picked = array_values(array_unique(array_map('strval', $value)));

        foreach ($picked as $choice) {
            if (! in_array($choice, $allowed, true)) {
                throw new ApiException('validation_failed', 'اختر من القائمة', 422);
            }
        }

        // Kept in the catalogue's order, so the stored list and the one the panel draws
        // cannot disagree about which comes first.
        return array_values(array_filter($allowed, static fn ($choice) => in_array($choice, $picked, true)));
    }
}
