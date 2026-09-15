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
        // Once per basket (doc 62 §9): a two-place order used to say it twice.
        'order.placed' => [
            'ar' => ['تم استلام طلبك', 'طلبك {order_number} بانتظار موافقة {places}'],
            'en' => ['Order placed', 'Your order {order_number} is waiting for {places}'],
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
        // The customer is told about their own order and nothing about our dispatcher
        // (operator, 2026-09-11). «جارٍ إشعار أقرب الكباتن» was a promise about a search
        // that could still be running twenty minutes later.
        'order.ready' => [
            'ar' => ['طلبك جاهز ✅', 'انتهى {restaurant} من تحضير طلبك'],
            'en' => ['Your order is ready ✅', '{restaurant} has finished preparing it'],
        ],
        // doc 62 §9 — how to recognise the captain at the door, not only a name.
        'order.driver_assigned' => [
            'ar' => ['الكابتن {driver} في الطريق 🛵', 'سيستلم طلبك من {restaurant} {vehicle}'],
            'en' => ['{driver} is on the way 🛵', 'Picking up your order from {restaurant} {vehicle}'],
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
        // The restaurant let the accept window run out. Until `cr7:tick` existed
        // nothing wrote this status, so this copy is new with the sweeper.
        'order.timeout' => [
            'ar' => ['لم يردّ المطعم بعد', 'طلبك {order_number} ما زال بانتظار ردّ {restaurant} — يمكنك إلغاؤه'],
            'en' => ['The restaurant has not answered', 'Your order {order_number} is still waiting on {restaurant} — you may cancel it'],
        ],
        /*
         * `order.no_driver` is **deleted**, not merely unused.
         *
         * It went to the customer every time the dispatcher ran out of new captains to
         * ask — which a single «رفض» is enough to cause — and said «لم نجد كابتنًا
         * متاحًا لطلبك». Operator, 2026-09-11: a customer hears about their order, not
         * about our search. The place and the operators hear instead, at two minutes.
         */
        'order.issue' => [
            'ar' => ['توجد مشكلة في طلبك', 'سيتواصل معك فريق الدعم فورًا'],
            'en' => ["There's an issue with your order", 'Support is contacting you now'],
        ],

        // -------------------------------------------------------- restaurant
        // `{total}` is «حساب المكان» — what this place is owed for its own food. It was
        // the sub-order total, delivery and service fees included, which is the
        // customer's number and never the kitchen's (operator, 2026-09-11).
        'order.new' => [
            'ar' => ['🔔 طلب جديد {order_number}', '{items} أصناف · حساب المكان {total}'],
            'en' => ['🔔 New order {order_number}', '{items} items · your share {total}'],
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
        // The other side of `order.timeout`: the kitchen is the one holding it up.
        'order.timeout_restaurant' => [
            'ar' => ['⏰ الطلب {order_number} بانتظار ردّك', 'انتهت مهلة الرد — اقبل الطلب أو ارفضه الآن'],
            'en' => ['⏰ Order {order_number} is waiting for you', 'The accept window is over — accept or reject it now'],
        ],
        // Sent once, {minutes} minutes after the food was marked ready and still nobody
        // has taken it. The kitchen can do nothing about dispatch, so the line that
        // matters is the phone number of somebody who can.
        'order.no_driver_restaurant' => [
            'ar' => ['لم يقبل أي كابتن الطلب بعد', 'الطلب {order_number} جاهز منذ {minutes} دقيقة ولم يقبله أي كابتن — تواصل مع الدعم على {support_phone}'],
            'en' => ['No captain has taken the order yet', 'Order {order_number} has been ready for {minutes} min and no captain has accepted — contact support on {support_phone}'],
        ],
        // The board already draws «وصل الكابتن إلى المطعم». Nothing told a kitchen
        // that was not looking at the board.
        // doc 11 §3 listed it and nothing sent it: the place learns who is coming, and how
        // to reach him, the moment a captain takes the order (D-101).
        'order.driver_assigned_restaurant' => [
            'ar' => ['الكابتن {driver} في الطريق للاستلام', 'الطلب {order_number} · {captain}'],
            'en' => ['{driver} is coming to pick up', 'Order {order_number} · {captain}'],
        ],
        // D-103 — on a cash order the place is also told what the captain is about to pay.
        'order.driver_at_restaurant' => [
            'ar' => ['🛵 وصل الكابتن للاستلام', 'الطلب {order_number} — {handover}'],
            'en' => ['🛵 The captain has arrived', 'Order {order_number} — {handover}'],
        ],
        'order.delivered_restaurant' => [
            'ar' => ['تم تسليم الطلب {order_number} ✅', 'اكتمل الطلب وسيظهر في تسوياتك'],
            'en' => ['Order {order_number} delivered ✅', 'It is complete and will appear in your settlements'],
        ],
        'order.cancelled_restaurant' => [
            'ar' => ['تم إلغاء الطلب {order_number}', 'لا حاجة لتحضيره'],
            'en' => ['Order {order_number} was cancelled', 'No need to prepare it'],
        ],

        // ------------------------------------------------------------ driver
        // doc 62 §9 — the whole sentence, built per locale by `OfferContext`: the place
        // and the customer's area, how far the place is from the captain, how long the
        // trip is, and the earning — with why it is zero when it is. The old copy read
        // «‪0.00‬ ج.م» on every free first order with nothing to say why, and «—» for
        // the distance on every offer handed to a captain coming on shift.
        'offer.new' => [
            'ar' => ['🚨 طلب جديد قريب منك', '{summary}'],
            'en' => ['🚨 New order near you', '{summary}'],
        ],
        // doc 63 D-115 — a sibling of the basket this captain carries is ready, and it is
        // theirs: nobody else was offered it. `{number}` carries its own «#».
        'trip.order_ready' => [
            'ar' => ['طلب {number} من {place} جاهز — استلمه ضمن رحلتك', '{details}'],
            'en' => ['Order {number} from {place} is ready — pick it up on this trip', '{details}'],
        ],
        // doc 63 §7 — the captain carrying (or holding) a sub-order that was cancelled. Nothing
        // told them before: a captain on the way to a cancelled pickup learned it at the counter.
        'trip.order_cancelled' => [
            'ar' => ['أُلغي الطلب {number} — أُزيل من رحلتك', '{place}'],
            'en' => ['Order {number} was cancelled — removed from your trip', '{place}'],
        ],
        // doc 62 §9 — where to go, what to pay there, what to collect at the door.
        'order.assigned' => [
            'ar' => ['أصبح الطلب لك ✅', '{details}'],
            'en' => ['Order is yours ✅', '{details}'],
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

        // ------------------------------------------- scheduled orders (2026-09-07)
        // The customer chose a time hours ago and has almost certainly stopped thinking
        // about it, so the release has to announce itself — and so does a failure, with
        // the reason, because «لم يحدث شيء» is the worst possible answer to «طلبت لستة».
        'order.scheduled_released' => [
            'ar' => ['بدأ طلبك المجدول ⏰', 'أرسلنا الطلب {order_number} إلى المطعم الآن'],
            'en' => ['Your scheduled order has started ⏰', 'Order {order_number} was sent to the restaurant'],
        ],
        'order.scheduled_failed' => [
            'ar' => ['تعذّر تنفيذ طلبك المجدول', '{reference} — {reason}'],
            'en' => ['Your scheduled order could not be placed', '{reference} — {reason}'],
        ],

        // ----------------------------------------------- errands «اطلب كابتن»
        // Broadcast to every available captain, not to the nearest three: the fee is the
        // customer's offer and taking it is a decision only the captain can make.
        'errand.new' => [
            'ar' => ['🛵 مشوار جديد متاح', '{pickup} ← {dropoff} · {distance} كم · العميل يعرض {fee}'],
            'en' => ['🛵 New errand available', '{pickup} → {dropoff} · {distance} km · customer offers {fee}'],
        ],
        'errand.taken' => [
            'ar' => ['أصبح المشوار لك ✅', 'توجّه إلى {pickup} — {reference}'],
            'en' => ['The errand is yours ✅', 'Head to {pickup} — {reference}'],
        ],
        'errand.assigned_customer' => [
            'ar' => ['الكابتن {driver} في الطريق 🛵', 'سيستلم طلبك من {pickup}'],
            'en' => ['{driver} is on the way 🛵', 'Collecting from {pickup}'],
        ],
        'errand.picked_up_customer' => [
            'ar' => ['استلم الكابتن طلبك', 'في الطريق إليك الآن — الإجمالي المستحق {total}'],
            'en' => ['Your captain has collected it', 'On the way — total due {total}'],
        ],
        'errand.delivered_customer' => [
            'ar' => ['تم تسليم المشوار ✅', 'شكرًا لاستخدامك خدمة اطلب كابتن'],
            'en' => ['Errand delivered ✅', 'Thanks for using the errand service'],
        ],
        // The errand half of D-72. An operator's assignment is a question with two
        // answers, exactly like a food order's — same push, same accept/decline screen.
        'errand.offered' => [
            'ar' => ['📌 تم إسناد مشوار لك', '{reference} · من {pickup} · العميل يعرض {fee} — اقبله أو ارفضه'],
            'en' => ['📌 An errand was assigned to you', '{reference} · from {pickup} · customer offers {fee} — accept or decline'],
        ],
        'errand.offer_declined' => [
            'ar' => ['رفض الكابتن المشوار', 'رفض {driver} المشوار {reference} — عاد إلى لوحة المشاوير'],
            'en' => ['The captain declined', '{driver} declined errand {reference} — it is back on the board'],
        ],
        'errand.cancelled_driver' => [
            'ar' => ['أُلغي المشوار', 'المشوار {reference} لم يعد في قائمتك'],
            'en' => ['Errand cancelled', 'Errand {reference} is no longer yours'],
        ],
        // -------------------------------------- assignment, 2026-09-08 (D-72)
        // An operator handed this order to this captain by name. It is still an offer:
        // the same accept/reject screen, and a refusal goes back to whoever sent it.
        'offer.assigned' => [
            'ar' => ['📌 تم إسناد طلب لك', 'الطلب {order_number} · {summary} — اقبله أو ارفضه'],
            'en' => ['📌 An order was assigned to you', 'Order {order_number} · {summary} — accept or decline'],
        ],
        'offer.assignment_declined' => [
            'ar' => ['رفض الكابتن الإسناد', 'رفض {driver} الطلب {order_number} — السبب: {reason}'],
            'en' => ['The captain declined', '{driver} declined order {order_number} — reason: {reason}'],
        ],
        // No offer expires any more, so «ran out of rounds» can no longer be the thing
        // that reaches an operator. This is: still nobody, {minutes} minutes in.
        'order.unclaimed_admin' => [
            'ar' => ['طلب لم نجد له كابتن منذ {minutes} دقيقة', 'الطلب {order_number} من {restaurant} جاهز وما زال بانتظار كابتن — أسنده بنفسك'],
            'en' => ['No captain found for {minutes} min', 'Order {order_number} from {restaurant} is ready and still waiting — assign it yourself'],
        ],

        // ------------------------------------------------ complaints (D-53)
        'complaint.new_admin' => [
            'ar' => ['شكوى جديدة {ticket}', '{name} — {category}: {excerpt}'],
            'en' => ['New complaint {ticket}', '{name} — {category}: {excerpt}'],
        ],
        'complaint.reply' => [
            'ar' => ['رد جديد على شكواك {ticket}', '{excerpt}'],
            'en' => ['New reply on {ticket}', '{excerpt}'],
        ],
        'complaint.reply_admin' => [
            'ar' => ['رد جديد على الشكوى {ticket}', '{name}: {excerpt}'],
            'en' => ['New reply on complaint {ticket}', '{name}: {excerpt}'],
        ],
        'complaint.resolved' => [
            'ar' => ['تم إغلاق شكواك {ticket}', '{outcome}'],
            'en' => ['Your complaint {ticket} is closed', '{outcome}'],
        ],

        // ------------------------------------- account moderation (D-73)
        // Sent before the tokens are deleted, or the person never learns why the app
        // stopped letting them in.
        'account.blocked' => [
            'ar' => ['تم إيقاف حسابك', 'السبب: {reason} — للتواصل مع الدعم اتصل بنا'],
            'en' => ['Your account has been suspended', 'Reason: {reason} — contact support for help'],
        ],
        'account.restored' => [
            'ar' => ['تم إعادة تفعيل حسابك ✅', 'يمكنك استخدام التطبيق من جديد'],
            'en' => ['Your account is active again ✅', 'You can use the app as usual'],
        ],
        'account.reset' => [
            'ar' => ['تم إعادة تعيين بيانات حسابك', 'سجّل الدخول من جديد لإكمال بياناتك'],
            'en' => ['Your account data was reset', 'Sign in again to complete your profile'],
        ],

        // The escalation, doc 40 §3: nobody took it and the operator has to look.
        // ------------------------------------------------------------- doc 62
        // D-100 — the ring. Its feed row is `order.new`; these are the two data messages
        // the place's phone rings and stops on. The copy rides inside the data message
        // (Android) and as the alert (iOS).
        'order.ring' => [
            'ar' => ['🔔 طلب جديد {order_number}', '{items} أصناف · حساب المكان {total} · {payment}'],
            'en' => ['🔔 New order {order_number}', '{items} items · your share {total} · {payment}'],
        ],
        // D-104 — the counter, from both sides.
        'settlement.recorded' => [
            'ar' => ['تمت تسوية حسابك', '{summary} — {balance}'],
            'en' => ['Your account was settled', '{summary} — {balance}'],
        ],
        'settlement.paid' => [
            'ar' => ['تم تحويل مستحقاتك', 'حوّلت المنصة {amount} عن الفترة {period}'],
            'en' => ['Your payout was sent', 'The platform sent {amount} for {period}'],
        ],
        // The deadlines nothing used to enforce (doc 62 §9).
        'payment.due_reminder' => [
            'ar' => ['يتبقّى {minutes} دقائق على التحويل', 'سيُلغى الطلب {order_number} تلقائيًا إن لم يصل التحويل'],
            'en' => ['{minutes} min left to transfer', 'Order {order_number} is cancelled automatically if the transfer does not arrive'],
        ],
        'payment.expired' => [
            'ar' => ['تم إلغاء الطلب', 'لم يصلنا التحويل للطلب {order_number} في الوقت المحدد — يمكنك إعادة الطلب والدفع نقدًا'],
            'en' => ['Order cancelled', 'The transfer for order {order_number} did not arrive in time — you can reorder and pay cash'],
        ],
        'payment.expired_restaurant' => [
            'ar' => ['تم إلغاء الطلب {order_number}', 'لم يُتمّ العميل التحويل في الوقت المحدد — لا حاجة لتحضيره'],
            'en' => ['Order {order_number} was cancelled', 'The customer did not pay in time — no need to prepare it'],
        ],
        'order.confirm_expired' => [
            'ar' => ['تم إلغاء الطلب', 'لم يصلنا تأكيدك للطلب {order_number} في الوقت المحدد — يمكنك إعادة الطلب في أي وقت'],
            'en' => ['Order cancelled', 'We did not receive your confirmation of order {order_number} in time — you can reorder any time'],
        ],
        'order.confirm_expired_restaurant' => [
            'ar' => ['تم إلغاء الطلب {order_number}', 'لم يؤكّد العميل الطلب في الوقت المحدد — لا حاجة لتحضيره'],
            'en' => ['Order {order_number} was cancelled', 'The customer did not confirm in time — no need to prepare it'],
        ],
        // Operator alerts doc 11 §4 listed and nothing sent.
        'payment.proof_uploaded_admin' => [
            'ar' => ['إيصال تحويل بانتظار المراجعة', 'الطلب {order_number} · {amount} عبر {method} — راجعه قبل انتهاء المهلة'],
            'en' => ['A transfer receipt is waiting', 'Order {order_number} · {amount} via {method} — review it before the deadline'],
        ],
        'order.restaurant_timeout_admin' => [
            'ar' => ['مكان لم يرد على طلب', 'لم يرد {restaurant} على الطلب {order_number} خلال {minutes} دقائق'],
            'en' => ['A place has not answered', '{restaurant} has not answered order {order_number} in {minutes} min'],
        ],
        'order.delivery_issue_admin' => [
            'ar' => ['مشكلة في توصيل طلب', 'أبلغ الكابتن {driver} عن مشكلة في الطلب {order_number}'],
            'en' => ['A delivery has a problem', '{driver} reported a problem with order {order_number}'],
        ],
        'order.otp_locked_admin' => [
            'ar' => ['قُفل كود طلب', 'قُفل {kind} للطلب {order_number} بعد {attempts} محاولات خاطئة — الكابتن {driver} ينتظر'],
            'en' => ['An order code locked', 'The {kind} for order {order_number} locked after {attempts} wrong tries — {driver} is waiting'],
        ],
        'errand.no_driver_admin' => [
            'ar' => ['مشوار بلا كابتن منذ {minutes} دقيقة', '{reference} — {pickup} ← {dropoff} · العميل يعرض {fee}'],
            'en' => ['Errand unclaimed for {minutes} min', '{reference} — {pickup} → {dropoff} · offer {fee}'],
        ],
    ];

    /**
     * @param  array<string, string|int|float|array{ar: string, en: string}|null>  $context
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

    /** @param array<string, string|int|float|array{ar: string, en: string}|null> $context */
    private static function fill(string $template, array $context, string $locale): string
    {
        $isArabic = ! str_starts_with($locale, 'en');

        $replacements = [];
        foreach ($context as $key => $value) {
            // A value may be a sentence per locale — `['ar' => …, 'en' => …]` — when it has
            // words around its numbers. Such a value arrives already isolated: wrapping a
            // whole Arabic sentence in an LTR isolate would lay it out left to right.
            if (is_array($value)) {
                $replacements['{'.$key.'}'] = (string) ($value[$isArabic ? 'ar' : 'en'] ?? $value['ar'] ?? '');

                continue;
            }

            $replacements['{'.$key.'}'] = $isArabic
                ? Isolate::ltrIfNumeric((string) $value)
                : (string) $value;
        }

        // A placeholder with no value left in the sentence reads as a bug to the
        // customer, so an unfilled one is removed rather than shown.
        return trim(preg_replace('/\{[a-z_]+\}/', '', strtr($template, $replacements)) ?? '');
    }
}
