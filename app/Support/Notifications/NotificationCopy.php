<?php

namespace App\Support\Notifications;

/**
 * doc 11 §1–3 as data: `type` → title and body, in both locales.
 *
 * The Arabic here is the record. It is Modern Standard Arabic (D-44) and every
 * number inside a sentence is wrapped in an LTR isolate by [Isolate], because an
 * unisolated four-digit code beside Arabic punctuation renders reversed — which
 * in `order.picked_up` means handing the customer a delivery code with its
 * digits in the wrong order, and the captain then refusing a correct delivery.
 *
 * A type this table does not know is **not** an error: [copy] falls back to the
 * type itself as the title. A push that arrives with a dull title is a smaller
 * failure than a status change that throws because someone added a state before
 * they added its copy.
 */
class NotificationCopy
{
    /**
     * `{placeholder}` names match the keys of the context array each caller passes.
     *
     * @var array<string, array{ar: array{0: string, 1: string}, en: array{0: string, 1: string}}>
     */
    private const TABLE = [
        // ---------------------------------------------------------- customer
        'order.placed' => [
            'ar' => ['تم استلام طلبك', 'طلبك {order_number} بانتظار موافقة المطعم'],
            'en' => ['Order placed', 'Your order {order_number} is waiting for the restaurant'],
        ],
        'order.confirm_required' => [
            'ar' => ['ردّت كل المطاعم — يرجى تأكيد طلبك', 'راجع الطلب والإجمالي ({amount}) وأكّده ليبدأ التحضير'],
            'en' => ['All restaurants answered — please confirm', 'Review your order and total ({amount}) and confirm so the kitchen can start'],
        ],
        'order.confirmed' => [
            'ar' => ['تم تأكيد طلبك 🎉', 'سيبدأ {restaurant} التحضير الآن'],
            'en' => ['Order confirmed 🎉', '{restaurant} is starting now'],
        ],
        'payment.due' => [
            'ar' => ['يرجى إتمام التحويل', 'حوّل {amount} ليبدأ المطعم التحضير'],
            'en' => ['Complete your payment', 'Transfer {amount} so the kitchen can start'],
        ],
        'order.preparing' => [
            'ar' => ['جارٍ تحضير طلبك 👨‍🍳', 'يتبقّى {prep_minutes} دقيقة تقريبًا'],
            'en' => ['Preparing your food 👨‍🍳', 'About {prep_minutes} min left'],
        ],
        'order.ready' => [
            'ar' => ['طلبك جاهز', 'جارٍ إشعار أقرب الكباتن إليك'],
            'en' => ['Your order is ready', 'Notifying the nearest captains'],
        ],
        'order.driver_assigned' => [
            'ar' => ['الكابتن {driver} في الطريق 🛵', 'سيستلم طلبك من {restaurant}'],
            'en' => ['{driver} is on the way 🛵', 'Picking up your order from {restaurant}'],
        ],
        'order.picked_up' => [
            'ar' => ['استلم الكابتن طلبك — كود التسليم {delivery_otp}', 'أعطِ الكود {delivery_otp} للكابتن عند الاستلام'],
            'en' => ['Order picked up — delivery code {delivery_otp}', 'Give the captain code {delivery_otp} on arrival'],
        ],
        'order.arrived' => [
            'ar' => ['وصل الكابتن 📍', 'الكابتن بانتظارك — كود التسليم {delivery_otp}'],
            'en' => ['Your captain has arrived 📍', 'Waiting outside — delivery code {delivery_otp}'],
        ],
        'order.delivered' => [
            'ar' => ['تم التوصيل ✅', 'نتمنى لك وجبة شهية — يسعدنا تقييم تجربتك'],
            'en' => ['Delivered ✅', 'Enjoy! Rate your experience'],
        ],
        'order.cancelled' => [
            'ar' => ['تم إلغاء الطلب', 'تم إلغاء الطلب {order_number}'],
            'en' => ['Order cancelled', 'Order {order_number} was cancelled'],
        ],
        'order.issue' => [
            'ar' => ['توجد مشكلة في طلبك', 'سيتواصل معك فريق الدعم فورًا'],
            'en' => ["There's an issue with your order", 'Support is contacting you now'],
        ],

        // -------------------------------------------------------- restaurant
        'order.new' => [
            'ar' => ['🔔 طلب جديد {order_number}', '{items} أصناف · {total}'],
            'en' => ['🔔 New order {order_number}', '{items} items · {total}'],
        ],
        'order.awaiting_customer_confirmation' => [
            'ar' => ['⏳ الطلب {order_number} بانتظار تأكيد العميل', 'لا تبدأ التحضير الآن'],
            'en' => ['⏳ Order {order_number} is waiting for the customer', 'Do not start cooking yet'],
        ],
        'order.confirmed_restaurant' => [
            'ar' => ['✅ أكّد العميل الطلب {order_number}', 'أدخل رقم الطلب في نظامك وابدأ التحضير'],
            'en' => ['✅ Customer confirmed order {order_number}', 'Enter your order number and start preparing'],
        ],
        'order.prep_timer_expired' => [
            'ar' => ['⏱️ انتهى وقت تحضير الطلب {order_number}', 'تم إشعار أقرب الكباتن'],
            'en' => ['⏱️ Prep time is up for {order_number}', 'The nearest captains have been notified'],
        ],
        'order.cancelled_restaurant' => [
            'ar' => ['تم إلغاء الطلب {order_number}', 'لا حاجة لتحضيره'],
            'en' => ['Order {order_number} was cancelled', 'No need to prepare it'],
        ],

        // ------------------------------------------------------------ driver
        'offer.new' => [
            'ar' => ['🚨 طلب جديد قريب منك', '{restaurant} · {distance} كم · أرباحك {earning}'],
            'en' => ['🚨 New order near you', '{restaurant} · {distance} km · you earn {earning}'],
        ],
        'order.assigned' => [
            'ar' => ['أصبح الطلب لك ✅', 'رقم الطلب في المطعم {restaurant_order_ref}'],
            'en' => ['Order is yours ✅', 'Restaurant order number {restaurant_order_ref}'],
        ],
        'order.ready_for_pickup' => [
            'ar' => ['الطلب جاهز للاستلام', 'رقمه في المطعم {restaurant_order_ref}'],
            'en' => ['Order is ready for pickup', 'Restaurant order number {restaurant_order_ref}'],
        ],
        'order.reassigned' => [
            'ar' => ['تم نقل الطلب إلى كابتن آخر', 'الطلب {order_number} لم يعد في قائمتك'],
            'en' => ['Order reassigned', 'Order {order_number} is no longer yours'],
        ],
        'order.unassigned' => [
            'ar' => ['تم سحب الطلب من قائمتك', 'الطلب {order_number} لم يعد في قائمتك'],
            'en' => ['Order removed from your list', 'Order {order_number} is no longer yours'],
        ],
    ];

    /**
     * @param  array<string, string|int|float|null>  $context
     * @return array{title: string, body: string}
     */
    public static function copy(string $type, string $locale, array $context = []): array
    {
        $entry = self::TABLE[$type] ?? null;

        if ($entry === null) {
            return ['title' => $type, 'body' => ''];
        }

        $line = $entry[str_starts_with($locale, 'en') ? 'en' : 'ar'];

        return [
            'title' => self::fill($line[0], $context, $locale),
            'body' => self::fill($line[1], $context, $locale),
        ];
    }

    public static function knows(string $type): bool
    {
        return isset(self::TABLE[$type]);
    }

    /** @param array<string, string|int|float|null> $context */
    private static function fill(string $template, array $context, string $locale): string
    {
        $isArabic = ! str_starts_with($locale, 'en');

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = $isArabic
                ? Isolate::ltrIfNumeric((string) $value)
                : (string) $value;
        }

        // A placeholder with no value left in the sentence reads as a bug to the
        // customer, so an unfilled one is removed rather than shown.
        return trim(preg_replace('/\{[a-z_]+\}/', '', strtr($template, $replacements)) ?? '');
    }
}
