<?php

namespace App\Support\Legal;

/**
 * The legal text of the platform — one source for three readers:
 *
 *  - the public web pages (`/privacy`, `/terms`, `/delete-account`, `/support`), which the
 *    Google Play and App Store listings link to;
 *  - `GET /pages/privacy` and `GET /pages/terms`, the in-app copy, written into the
 *    `static_pages` table by `ContentSeeder` and `php artisan content:sync-legal`;
 *  - nothing else. Neither client keeps a copy.
 *
 * ⚠️ **A human must review this text before store submission.** It was drafted from what the
 * code actually does (batch 72, 2026-09-19) and it is not legal advice. The operator — and,
 * ideally, a lawyer who knows Egyptian law — has to confirm: the legal entity's name and
 * address (none is printed), the retention statements, the 30-day erasure promise, the
 * governing-law sentence and the age limit. `docs/72-…` lists the open points.
 *
 * Format — the same the in-app viewer already understands (it splits on blank lines and shows
 * each block): `## heading`, paragraphs, and blocks made only of `- ` bullets or `1. ` steps.
 * Modern Standard Arabic only (D-44). In Arabic a number is written `[[30]]`, which becomes an
 * LTR isolate so it cannot render reversed beside Arabic punctuation (doc 13 §4).
 */
final class LegalContent
{
    /** The «last updated» date printed on every page. Bump it when the text changes. */
    public const UPDATED = '2026-09-19';

    /** @return array{slug: string, title_ar: string, title_en: string, body_ar: string, body_en: string} */
    public static function privacy(): array
    {
        return [
            'slug' => 'privacy',
            'title_ar' => 'سياسة الخصوصية',
            'title_en' => 'Privacy policy',
            'body_ar' => self::ar(<<<'AR'
                ## من نحن
                CR7 Delivery منصة لتوصيل الطعام تعمل في جمصة، وتربط العملاء بالمطاعم وبكباتن التوصيل. تسري هذه السياسة على تطبيق العملاء وتطبيق الكباتن ولوحتي تحكم المطاعم والإدارة، وتبيّن ما نجمعه من بيانات، ولماذا، ومع من نشاركه، وكيف تطلب حذفه.

                ## ما نجمعه من كل مستخدم
                - الاسم ورقم الهاتف، ويُتحقق من الرقم برمز يصل برسالة نصية قصيرة عند تسجيل الدخول.
                - البريد الإلكتروني إن اخترت إضافته.
                - كلمة المرور للحسابات التي تنشئها الإدارة (الكباتن والمطاعم والموظفون)، وتُحفظ مشفّرة بصيغة لا يمكن عكسها.
                - معرّف عشوائي للجهاز يولّده التطبيق ويحتفظ به، ورمز الإشعارات (FCM)، ونوع الجهاز ونظام التشغيل وإصدار التطبيق.
                - سجلات تقنية على الخادم (عنوان IP وتوقيت الطلبات ونوع التطبيق) لحماية الحسابات وكشف إساءة الاستخدام وإصلاح الأعطال.

                ## ما نجمعه من العملاء
                - عناوين التوصيل التي تحفظها وموضع الدبوس على الخريطة.
                - سجل الطلبات: الأصناف والمبالغ وطريقة الدفع والتوقيت والحالة.
                - موقعك الدقيق أثناء استخدامك التطبيق فقط، لعرض المطاعم التي تصل إليك وحساب رسوم التوصيل وتحديد عنوانك. يمكنك رفض الإذن واختيار العنوان يدويًا.
                - التقييمات والشكاوى وطلبات المشاوير، بما فيها النصوص والصور التي ترفقها.
                - صورة إيصال التحويل ورقم العملية إذا دفعت بفودافون كاش أو إنستا باي.

                ## ما نجمعه من الكباتن
                - الموقع الدقيق، بما في ذلك أثناء عمل التطبيق في الخلفية، طوال الوقت الذي تكون فيه على الوردية (متاحًا لاستلام الطلبات). نستخدمه لاقتراح الطلبات القريبة، وتتبّع التوصيل وعرضه على العميل والمطعم أثناء الطلب، واحتساب المسافة والمستحقات، وحل النزاعات. لا نتتبّع موقعك وأنت خارج الوردية.
                - صورة رخصة القيادة وصورة البطاقة الشخصية والرقم القومي والصورة الشخصية، للتحقق من الهوية والتعاقد معك. تُحفظ الوثائق في تخزين خاص لا يمكن الوصول إليه برابط عام، ولا يطّلع عليها إلا موظفو الإدارة المخوّلون.
                - سجل الرحلات والمحفظة والتسويات المالية وصور إيصالات المشتريات في المشاوير.

                ## المطاعم
                نجمع بيانات النشاط (الاسم والهاتف والعنوان والمنيو والصور) وسجل الطلبات والتسويات المالية للمطعم.

                ## الأذونات التي يطلبها التطبيق
                - الموقع: لما سبق. للعملاء أثناء الاستخدام فقط، وللكباتن أثناء الوردية (وفي الخلفية).
                - الكاميرا والصور: فقط حين تختار إرفاق صورة (إيصال أو شكوى أو وثيقة).
                - الإشعارات: لإبلاغك بحالة الطلب والعروض الجديدة.

                ## كيف نستخدم بياناتك
                لتشغيل الخدمة: إنشاء الحساب والتحقق منه، واستلام الطلبات وتوجيهها إلى المطعم والكابتن، وحساب الرسوم والتسويات، وتتبّع التوصيل، والرد على الشكاوى والدعم، وحماية المنصة من الاحتيال وإساءة الاستخدام، والالتزام بما يفرضه القانون. لا نبيع بياناتك، ولا نستخدمها للإعلانات، ولا يتضمن التطبيق أي مكتبات إعلانية أو مكتبات تحليلات أو رصد أعطال من أطراف ثالثة.

                ## مع من نشاركها
                - المطعم: اسمك الأول وأصناف طلبك وملاحظاته.
                - الكابتن: اسمك ورقم هاتفك وعنوانك وموضع الدبوس، طوال الرحلة فقط. وترى أنت اسم الكابتن ورقمه وموقعه المباشر أثناء الطلب.
                - مزوّدو الخدمة الذين يعالجون بيانات بالنيابة عنا: Firebase Cloud Messaging من Google لإرسال الإشعارات (رمز الجهاز ونص الإشعار)؛ Pusher (المجموعة الأوروبية) لتحديثات الطلب الفورية؛ Mapbox وOpenFreeMap لخرائط العرض؛ Mapbox وOpenStreetMap للبحث عن العناوين ورسم المسارات، ويستلمان نصّ البحث أو الإحداثيات من خادمنا؛ ومزوّد الرسائل النصية ZADX لإرسال رمز التحقق إلى رقم هاتفك.
                - الجهات الرسمية عند وجود التزام قانوني أو أمر قضائي.
                قد تُعالَج بعض هذه البيانات خارج مصر لدى المزوّدين المذكورين.

                ## المدفوعات
                الدفع نقدًا عند الاستلام، أو تحويلًا يدويًا عبر فودافون كاش أو إنستا باي إلى أرقام المنصة المعروضة في التطبيق. يتم التحويل خارج التطبيق. لا نجمع ولا نحفظ بيانات أي بطاقة مصرفية، ولا نرى إلا صورة الإيصال ورقم العملية اللذين ترفعهما أنت. الخدمة توصيل طعام مادي فقط.

                ## الاحتفاظ والحذف
                يمكن للعميل حذف حسابه من داخل التطبيق: شاشة الحساب ← «حذف الحساب». أما حسابات الكباتن والمطاعم فتديرها الإدارة، ويُطلب حذفها بمراسلتنا. عند الحذف يُغلق الحساب فورًا، ويُستبدل اسمك بـ«مستخدم محذوف»، ويُستبدل هاتفك وبريدك بقيم مجهولة، ويُفصل ربط صورتك الشخصية بالحساب، وتُحذف رموز الإشعارات وتنتهي كل جلسات الدخول.
                نحتفظ بسجلات الطلبات والمدفوعات والتسويات، بما فيها عنوان التوصيل المدوّن على الطلب، وبالشكاوى والتقييمات، وللكباتن بسجل الرحلات والمحفظة، وذلك لأغراض المحاسبة والضرائب وتسوية النزاعات ومنع الاحتيال وما يفرضه القانون. تبقى هذه السجلات مرتبطة بمعرّف داخلي لم يعد يحمل اسمك ولا هاتفك ولا بريدك.
                زر الحذف في التطبيق لا يمحو تلقائيًا عناوينك المحفوظة ولا الصور والوثائق التي رفعتها. إن أردت محوها أو إخفاء هويتها فاطلب ذلك عبر صفحة حذف الحساب وسنحذف ما لا يلزمنا الاحتفاظ به قانونًا.
                لإجراءات الحذف بالتفصيل، بما فيها الطلب بالمراسلة لمن لا يستطيع فتح التطبيق، راجع صفحة حذف الحساب.

                ## حماية البيانات
                تنتقل البيانات بين التطبيق والخادم عبر اتصال مشفّر (HTTPS). تُحفظ كلمات المرور مشفّرة، وتُحفظ وثائق الكباتن وإيصالات الدفع في تخزين خاص، ويقتصر الاطلاع على البيانات على من تتطلبه مهمته بحسب صلاحياته. لا توجد وسيلة نقل أو تخزين آمنة تمامًا، ونعمل على تقليل المخاطر باستمرار.

                ## حقوقك
                يحق لك طلب الاطلاع على بياناتك وتصحيحها وحذفها والاعتراض على معالجتها، وذلك وفق القوانين المعمول بها، ومنها القانون رقم [[151]] لسنة [[2020]] بشأن حماية البيانات الشخصية. تواصل معنا عبر صفحة الدعم وسنرد خلال مدة معقولة.

                ## الأطفال
                الخدمة غير موجّهة لمن هم دون [[16]] عامًا، ولا نجمع عمدًا بيانات شخصية منهم. إن علمت أن طفلًا سجّل بياناته فراسلنا لنحذفها.

                ## التعديلات
                قد نحدّث هذه السياسة، وسنعرض تاريخ آخر تحديث في أعلى الصفحة. الاستمرار في استخدام الخدمة بعد التحديث يعني قبوله.

                ## التواصل
                عبر صفحة الدعم أو من شاشة المساعدة داخل التطبيق.
                AR),
            'body_en' => <<<'EN'
                ## Who we are
                CR7 Delivery is a food-delivery platform operating in Gamasa. It connects customers with restaurants and delivery captains. This policy covers the customer app, the captain app and the restaurant and admin dashboards, and explains what we collect, why, who receives it, and how to ask for it to be deleted.

                ## What we collect from every user
                - Your name and phone number. The number is verified with a one-time code sent by SMS when you sign in.
                - Your email address, if you choose to add one.
                - A password, for accounts the office creates (captains, restaurants, staff), stored only in a one-way hashed form.
                - A random device identifier that the app generates and keeps, your push-notification token (FCM), and the device type, operating system and app version.
                - Technical server logs (IP address, request times, app type) to protect accounts, detect abuse and fix faults.

                ## What we collect from customers
                - The delivery addresses you save and the map pin for each.
                - Your order history: items, amounts, payment method, times and status.
                - Your precise location while you are using the app, to show restaurants that reach you, price delivery and set your address. You can refuse the permission and pick an address manually.
                - Ratings, complaints and errand requests, including any text and photos you attach.
                - A photo of your transfer receipt and the transaction number, if you pay by Vodafone Cash or InstaPay.

                ## What we collect from captains
                - Precise location, including while the app runs in the background, for as long as you are on shift (available to take orders). We use it to offer nearby orders, to track the delivery and show it to the customer and the restaurant during that order, to calculate distance and earnings, and to resolve disputes. We do not track your location while you are off shift.
                - A photo of your driving licence, a photo of your national ID and your national ID number, and a profile photo, to verify your identity and contract with you. Documents are kept in private storage that has no public link and are viewed only by authorised office staff.
                - Trip history, wallet and financial settlements, and photos of purchase receipts on errands.

                ## Restaurants
                We collect the business's details (name, phone, address, menu, photos), its order history and its financial settlements.

                ## Permissions the app asks for
                - Location: for the purposes above — for customers while using the app only, for captains while on shift (including in the background).
                - Camera and photos: only when you choose to attach a picture (a receipt, a complaint, a document).
                - Notifications: to tell you about your order and new offers.

                ## How we use your data
                To run the service: create and verify your account, receive orders and route them to the restaurant and the captain, calculate fees and settlements, track delivery, answer complaints and support requests, protect the platform against fraud and abuse, and meet legal obligations. We do not sell your data, we do not use it for advertising, and the app contains no third-party advertising, analytics or crash-reporting libraries.

                ## Who receives it
                - The restaurant: your first name, the items you ordered and their notes.
                - The captain: your name, phone number, address and map pin, for the duration of the trip only. You see the captain's name, number and live location during your order.
                - Service providers that process data on our behalf: Firebase Cloud Messaging by Google to deliver notifications (device token and notification text); Pusher (EU cluster) for real-time order updates; Mapbox and OpenFreeMap for the map display; Mapbox and OpenStreetMap for address search and routing, which receive the search text or coordinates from our server; and the SMS gateway ZADX, which sends the verification code to your phone number.
                - Public authorities where the law or a court order requires it.
                Some of these providers process data outside Egypt.

                ## Payments
                You pay in cash on delivery, or by a manual Vodafone Cash or InstaPay transfer to the platform's numbers shown in the app. The transfer happens outside the app. We do not collect or store any bank-card details; we see only the receipt image and transaction number that you upload. The service is physical food delivery only.

                ## Retention and deletion
                A customer can delete their account inside the app: Account screen → Delete account. Captain and restaurant accounts are managed by the office and are deleted on request by writing to us. When an account is deleted, the account is closed immediately, your name is replaced with "Deleted user", your phone number and email are replaced with anonymous values, your profile photo is unlinked from the account, and your push tokens and all sign-in sessions are removed.
                We keep order, payment and settlement records — including the delivery address printed on an order — and complaints and ratings, and for captains the trip history and wallet, for accounting, tax, dispute resolution, fraud prevention and legal obligations. These records stay attached to an internal identifier that no longer carries your name, phone number or email.
                The Delete button does not automatically erase your saved addresses or the photos and documents you uploaded. If you want them erased or anonymised, ask us on the Delete account page and we will remove what we are not legally required to keep.
                The Delete account page describes the procedure in full, including how to ask by message if you cannot open the app.

                ## Security
                Data travels between the app and the server over an encrypted connection (HTTPS). Passwords are stored hashed, captain documents and payment receipts are kept in private storage, and access to data is limited to those who need it for their role. No method of transmission or storage is perfectly secure, and we keep working to reduce the risk.

                ## Your rights
                You may ask to access, correct or delete your data and to object to its processing, under the laws that apply, including Egypt's Law No. 151 of 2020 on the Protection of Personal Data. Contact us through the Support page and we will reply within a reasonable time.

                ## Children
                The service is not directed at anyone under 16 and we do not knowingly collect personal data from them. If you learn that a child has given us their data, write to us and we will delete it.

                ## Changes
                We may update this policy; the date of the latest update is shown at the top of the page. Continuing to use the service after an update means you accept it.

                ## Contact
                Through the Support page, or from the help screen inside the app.
                EN,
        ];
    }

    /** @return array{slug: string, title_ar: string, title_en: string, body_ar: string, body_en: string} */
    public static function terms(): array
    {
        return [
            'slug' => 'terms',
            'title_ar' => 'الشروط والأحكام',
            'title_en' => 'Terms and conditions',
            'body_ar' => self::ar(<<<'AR'
                ## القبول والخدمة
                باستخدامك تطبيق CR7 Delivery أو لوحات التحكم التابعة له فإنك توافق على هذه الشروط. المنصة وسيط بين العميل والمطعم والكابتن: تستقبل الطلب وتوجّهه إلى المطعم، وتتولى توصيله وتحصيل قيمته. الخدمة توصيل طعام مادي داخل نطاق التغطية المعروض في التطبيق.

                ## الحساب
                تسجّل برقم هاتفك، ويجب أن تكون بياناتك صحيحة وأن يكون الرقم رقمك أنت. أنت مسؤول عن كل ما يحدث عبر حسابك. حسابات الكباتن والمطاعم والموظفين تنشئها الإدارة.

                ## الطلبات والأسعار
                الأسعار المعروضة هي أسعار المطعم وقت الطلب وتشمل قيمة الأصناف فقط. تُضاف إليها رسوم التوصيل ورسوم الخدمة، ويظهر كل بند في ملخّص الطلب قبل التأكيد. يمكنك الطلب من ثلاثة مطاعم كحد أقصى في سلة واحدة برسوم توصيل واحدة.
                لا يُعتمد الطلب إلا بعد رد المطعم عليه وتأكيدك أنت للمبلغ النهائي.

                ## الدفع
                - الدفع نقدًا عند الاستلام، وقد تحدّد المنصة حدًا أقصى لقيمة الطلب الذي يُدفع نقدًا، وما زاد عليه يُدفع بالتحويل.
                - أو تحويلًا يدويًا عبر فودافون كاش أو إنستا باي إلى أرقام المنصة المعروضة في شاشة الطلب. يجب رفع إثبات الدفع خلال المهلة المحددة في الطلب، وإلا أُلغي الطلب.
                - لا تقبل المنصة الدفع ببطاقة مصرفية داخل التطبيق.

                ## الإلغاء
                يمكنك إلغاء الطلب قبل أن يبدأ المطعم في التحضير. بعد بدء التحضير لا يمكن الإلغاء من داخل التطبيق، ويمكنك التواصل مع الدعم. قد تُطبَّق رسوم عند الإلغاء المتأخر بحسب ما يظهر لك قبل التأكيد.

                ## التوصيل وكود التسليم
                مواعيد الوصول تقديرية. يظهر لك كود التسليم بعد استلام الكابتن للطلب، ولا يكتمل الطلب إلا بتسليمه للكابتن عند الاستلام. لا تعطِ الكود لأي شخص قبل أن يصل إليك الطلب.

                ## الاستخدام المقبول
                يُمنع إساءة استخدام المنصة أو إنشاء طلبات وهمية أو انتحال هوية الغير أو مضايقة الكباتن والمطاعم والموظفين أو محاولة الوصول إلى بيانات ليست لك. يحق لنا إيقاف الحساب المخالف.

                ## الشكاوى
                يمكنك رفع شكوى على أي طلب من داخل التطبيق مع صور إن لزم، وتُراجَع من الإدارة. المطعم مسؤول عن جودة الطعام وسلامته، والمنصة مسؤولة عن التوصيل وعن معالجة الشكاوى المتعلقة به.

                ## حدود المسؤولية
                نبذل العناية المعقولة لتقديم الخدمة دون انقطاع، لكنها تُقدَّم كما هي، وقد تتأثر بالمرور والطقس وحالة المطاعم وشبكة الاتصالات. في الحدود التي يسمح بها القانون لا نتحمل الأضرار غير المباشرة.

                ## الخصوصية
                تُعالَج بياناتك وفق سياسة الخصوصية المنشورة على صفحتها.

                ## التعديلات والقانون
                قد نعدّل هذه الشروط، وسنعرض تاريخ آخر تحديث، والاستمرار في الاستخدام بعده يعني قبوله. تخضع هذه الشروط للقوانين المعمول بها في جمهورية مصر العربية.

                ## التواصل
                عبر صفحة الدعم أو من شاشة المساعدة داخل التطبيق.
                AR),
            'body_en' => <<<'EN'
                ## Acceptance and the service
                By using the CR7 Delivery app or its dashboards you agree to these terms. The platform is an intermediary between the customer, the restaurant and the captain: it receives your order, routes it to the restaurant, delivers it and collects payment. The service is physical food delivery within the coverage area shown in the app.

                ## Your account
                You register with your phone number. Your details must be accurate and the number must be yours. You are responsible for everything done through your account. Captain, restaurant and staff accounts are created by the office.

                ## Orders and prices
                Prices shown are the restaurant's price at the time of ordering and cover the items only. A delivery fee and a service fee are added on top, and every line appears in the order summary before you confirm. You may order from up to three restaurants in one basket, with a single delivery fee.
                An order is not final until the restaurant has answered it and you have confirmed the final amount.

                ## Payment
                - Cash on delivery. The platform may set a maximum order value for cash; above it you pay by transfer.
                - Or a manual Vodafone Cash or InstaPay transfer to the platform's numbers shown on the order screen. Proof of payment must be uploaded within the deadline shown on the order, otherwise the order is cancelled.
                - The platform does not accept bank-card payments inside the app.

                ## Cancellation
                You may cancel before the restaurant starts preparing. After that the app cannot cancel it — contact support. A fee may apply to a late cancellation, as shown to you before you confirm.

                ## Delivery and the delivery code
                Arrival times are estimates. The delivery code is shown to you once the captain has collected the order, and the order is complete only when you give it to the captain on handover. Do not give the code to anyone before the order reaches you.

                ## Acceptable use
                You must not misuse the platform, place fake orders, impersonate anyone, harass captains, restaurants or staff, or try to reach data that is not yours. We may suspend an account that breaks these rules.

                ## Complaints
                You can raise a complaint on any order from inside the app, with photos where needed, and the office reviews it. The restaurant is responsible for the quality and safety of the food; the platform is responsible for delivery and for handling delivery-related complaints.

                ## Limits of liability
                We take reasonable care to run the service without interruption, but it is provided as is and can be affected by traffic, weather, restaurant conditions and mobile networks. To the extent the law allows, we are not liable for indirect damages.

                ## Privacy
                Your data is handled as described in the Privacy policy.

                ## Changes and governing law
                We may amend these terms and will show the date of the latest update; continuing to use the service afterwards means you accept them. These terms are governed by the laws in force in the Arab Republic of Egypt.

                ## Contact
                Through the Support page, or from the help screen inside the app.
                EN,
        ];
    }

    /**
     * The account-deletion page (web only — Google Play asks for a public URL that explains
     * how to delete an account and what happens to the data).
     *
     * @return array{slug: string, title_ar: string, title_en: string, body_ar: string, body_en: string}
     */
    public static function deleteAccount(): array
    {
        return [
            'slug' => 'delete-account',
            'title_ar' => 'حذف الحساب',
            'title_en' => 'Delete your account',
            'body_ar' => self::ar(<<<'AR'
                ## الطريقة الأولى: من داخل التطبيق
                هذه أسرع طريقة، وهي متاحة لحسابات العملاء. أما حسابات الكباتن والمطاعم فتديرها الإدارة، فاستخدم الطريقة الثانية.
                1. افتح تطبيق CR7 Delivery وسجّل الدخول.
                2. افتح شاشة الحساب ومرّر إلى أسفل القائمة ثم اختر «حذف الحساب».
                3. اختر سبب الحذف إن أردت، واكتب كلمة التأكيد التي تظهر لك («حذف» في النسخة العربية) ثم أكّد.
                لا يمكن حذف الحساب أثناء وجود طلب جارٍ؛ انتظر اكتماله أو ألغِه أولًا.

                ## الطريقة الثانية: بطلب مكتوب
                إن كان حسابك حساب كابتن أو مطعم، أو لم تستطع فتح التطبيق (فقدت الهاتف، أو حذفت التطبيق، أو لا تستطيع تسجيل الدخول) فأرسل لنا رسالة عبر وسائل التواصل المعروضة أسفل هذه الصفحة تتضمن:
                - رقم الهاتف المسجَّل به الحساب.
                - نوع الحساب: عميل أو كابتن أو مطعم.
                - عبارة «أطلب حذف حسابي».
                - إن أردت أيضًا محو عناوينك المحفوظة والصور والوثائق التي رفعتها، فاذكر ذلك صراحة.

                ## كيف نتحقق من هويتك
                لا نحذف حسابًا بناءً على رسالة وحدها. سنتصل بالرقم المسجَّل أو نرسل إليه رمز تحقق نصيًا، ولا ننفّذ الطلب إلا بعد التأكد من أنك صاحب الرقم. وقد نسألك عن معلومة يعرفها صاحب الحساب فقط، مثل آخر طلب أو اسم المطعم لحسابات المطاعم. لن نطلب منك كلمة المرور أبدًا.
                ننفّذ الطلب بعد التحقق خلال مدة أقصاها [[30]] يومًا.

                ## ما الذي يحدث عند الحذف
                - يُغلق حسابك فورًا ولا يمكن الدخول به مرة أخرى.
                - يُستبدل اسمك بـ«مستخدم محذوف»، ويُستبدل رقم هاتفك وبريدك الإلكتروني بقيم مجهولة، ويُفصل ربط صورتك الشخصية بالحساب.
                - تُحذف رموز الإشعارات، وتنتهي كل جلسات تسجيل الدخول على جميع أجهزتك.
                - يتحرر رقم هاتفك ويمكنك التسجيل به من جديد كحساب جديد.

                ## ما الذي نحتفظ به ولماذا
                - سجلات الطلبات والمدفوعات والتسويات، بما فيها عنوان التوصيل المدوّن على كل طلب، وكذلك الشكاوى والتقييمات، وللكباتن سجل الرحلات والمحفظة. نحتفظ بها للمحاسبة والضرائب وتسوية النزاعات ومنع الاحتيال وما يفرضه القانون.
                - تظل هذه السجلات مرتبطة بمعرّف داخلي لم يعد يحمل اسمك ولا هاتفك ولا بريدك.
                - عناوينك المحفوظة والصور والوثائق التي رفعتها (وثائق الكباتن وإيصالات الدفع وصور الشكاوى) لا تُمحى تلقائيًا بزر الحذف في التطبيق. اطلب محوها بالطريقة الثانية وسنحذف كل ما لا يلزمنا الاحتفاظ به قانونًا، ونخبرك بما بقي وسببه.
                AR),
            'body_en' => <<<'EN'
                ## Option 1: inside the app
                This is the fastest way, and it is available for customer accounts. Captain and restaurant accounts are managed by the office, so use Option 2.
                1. Open the CR7 Delivery app and sign in.
                2. Open the Account screen, scroll to the bottom of the list and choose "Delete account".
                3. Pick a reason if you like, type the confirmation word shown ("delete" in the English app, "حذف" in the Arabic app) and confirm.
                An account cannot be deleted while an order is in progress; wait for it to finish or cancel it first.

                ## Option 2: by written request
                If yours is a captain or restaurant account, or you cannot open the app (you lost your phone, uninstalled it, or cannot sign in), send us a message through the contact channels shown at the bottom of this page, including:
                - The phone number the account is registered under.
                - The account type: customer, captain or restaurant.
                - The sentence "I request the deletion of my account".
                - If you also want your saved addresses and the photos and documents you uploaded erased, say so explicitly.

                ## How we verify it is you
                We do not delete an account on the strength of a message alone. We will call the registered number or send a verification code to it by SMS, and we act only once we are satisfied that you hold that number. We may also ask a detail only the account holder would know, such as your last order, or the restaurant's name for a restaurant account. We will never ask for your password.
                Once verified, we carry the request out within 30 days at most.

                ## What happens when you delete
                - Your account is closed at once and cannot be signed in to again.
                - Your name is replaced with "Deleted user", your phone number and email are replaced with anonymous values, and your profile photo is unlinked from the account.
                - Your push tokens are removed and every sign-in session on all your devices ends.
                - Your phone number is released, so you can register with it again as a new account.

                ## What we keep, and why
                - Order, payment and settlement records — including the delivery address printed on each order — plus complaints and ratings, and for captains the trip history and wallet. We keep them for accounting, tax, dispute resolution, fraud prevention and legal obligations.
                - These records stay attached to an internal identifier that no longer carries your name, phone number or email.
                - Your saved addresses and the photos and documents you uploaded (captain documents, payment receipts, complaint photos) are not erased automatically by the Delete button in the app. Ask for them under Option 2 and we will delete everything we are not legally required to keep, and tell you what remains and why.
                EN,
        ];
    }

    /** @return array{slug: string, title_ar: string, title_en: string, body_ar: string, body_en: string} */
    public static function support(): array
    {
        return [
            'slug' => 'support',
            'title_ar' => 'الدعم',
            'title_en' => 'Support',
            'body_ar' => self::ar(<<<'AR'
                ## كيف نساعدك
                داخل التطبيق: افتح «المساعدة» لتجد الأسئلة الشائعة وتتواصل معنا بخصوص طلبك، ومن شاشة الطلب يمكنك رفع شكوى مع صور.
                خارج التطبيق: استخدم وسائل التواصل المعروضة أسفل هذه الصفحة، واذكر رقم الهاتف المسجَّل به حسابك ورقم الطلب إن وُجد.

                ## طلبات الخصوصية
                لطلب حذف حسابك أو بياناتك راجع صفحة حذف الحساب، وتجد سياسة الخصوصية والشروط والأحكام في روابط الصفحة.
                AR),
            'body_en' => <<<'EN'
                ## How we can help
                Inside the app: open "Help" for the frequently asked questions and to contact us about your order, and raise a complaint with photos from the order screen.
                Outside the app: use the contact channels at the bottom of this page, and include the phone number your account is registered under and the order number, if any.

                ## Privacy requests
                To ask for your account or your data to be deleted, see the Delete account page. The Privacy policy and the Terms are linked from every page.
                EN,
        ];
    }

    /** Numbers in Arabic prose become LTR isolates so they never render reversed. */
    private static function ar(string $body): string
    {
        return (string) preg_replace('/\[\[(.+?)\]\]/u', "\u{202A}$1\u{202C}", $body);
    }

    /** The in-app pages this class owns: what `ContentSeeder` and `content:sync-legal` write. */
    public static function inAppPages(): array
    {
        return [self::terms(), self::privacy()];
    }

    /**
     * A body as safe HTML: `## ` → `<h2>`, blocks of `- ` → `<ul>`, blocks of `1. ` → `<ol>`,
     * everything else → `<p>` (a paragraph's lines are kept, one `<br>` apart). Every character
     * of the source is escaped; nothing here can inject markup.
     */
    public static function html(string $body): string
    {
        $out = '';

        foreach (preg_split('/\n\s*\n/', trim(self::dedent($body))) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $lines = array_map('trim', explode("\n", $block));

            // A heading may lead a block that carries more lines (the seed's own layout).
            if (str_starts_with($lines[0], '## ')) {
                $out .= '<h2>'.e(substr(array_shift($lines), 3)).'</h2>';
                $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));

                if ($lines === []) {
                    continue;
                }
            }

            $out .= self::blockHtml($lines);
        }

        return $out;
    }

    /** @param  list<string>  $lines */
    private static function blockHtml(array $lines): string
    {
        // Split the lines into runs of bullets, runs of numbered steps and plain text.
        $html = '';
        $run = [];
        $kind = null;

        $flush = function () use (&$html, &$run, &$kind): void {
            if ($run === []) {
                return;
            }

            $html .= match ($kind) {
                'ul' => '<ul>'.implode('', array_map(fn ($l) => '<li>'.e($l).'</li>', $run)).'</ul>',
                'ol' => '<ol>'.implode('', array_map(fn ($l) => '<li>'.e($l).'</li>', $run)).'</ol>',
                default => '<p>'.implode('<br>', array_map(fn ($l) => e($l), $run)).'</p>',
            };
            $run = [];
        };

        foreach ($lines as $line) {
            if (str_starts_with($line, '- ')) {
                $next = 'ul';
                $text = substr($line, 2);
            } elseif (preg_match('/^\d+\.\s+(.*)$/u', $line, $m) === 1) {
                $next = 'ol';
                $text = $m[1];
            } else {
                $next = 'p';
                $text = $line;
            }

            if ($next !== $kind) {
                $flush();
                $kind = $next;
            }

            $run[] = $text;
        }

        $flush();

        return $html;
    }

    /** The heredocs are indented for readability in this file; the stored text is not. */
    private static function dedent(string $body): string
    {
        return implode("\n", array_map(fn ($line) => ltrim($line), explode("\n", $body)));
    }
}
