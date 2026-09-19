<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\StaticPage;
use App\Support\Legal\LegalContent;
use Illuminate\Database\Seeder;

/**
 * The three legal pages `GET /pages/{slug}` is called with, and the help centre.
 * Without these rows CUS-45, CUS-46 and CUS-47 return 404.
 *
 * Modern Standard Arabic only (D-44), western digits with an LTR isolate (doc 13 §4).
 * The copy is a working draft the operator should have reviewed before launch — it is not
 * legal advice. `terms` and `privacy` live in `LegalContent` (batch 72) and are shared with
 * the public web pages; `about` and the FAQ are below.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $page) {
            StaticPage::query()->updateOrCreate(['slug' => $page['slug']], $page);
        }

        foreach ($this->faqs() as $index => $faq) {
            Faq::query()->updateOrCreate(
                ['question_ar' => $faq['question_ar']],
                $faq + ['sort_order' => $index],
            );
        }
    }

    private function pages(): array
    {
        return [
            // Batch 72 — `terms` and `privacy` come from `LegalContent`, the one source the public
            // web pages share. ⚠️ A human must review that text before store submission.
            ...array_map(fn (array $page) => $page + ['is_active' => true], LegalContent::inAppPages()),
            [
                'slug' => 'about',
                'title_ar' => 'عن CR7 Delivery',
                'title_en' => 'About CR7 Delivery',
                'body_ar' => <<<'AR'
                    CR7 Delivery منصة توصيل طعام في جمصة، تجمع مطاعم المدينة في مكان واحد وتوصّل طلبك بأسطول كباتن خاص بها.

                    يمكنك الطلب من ثلاثة مطاعم في سلة واحدة، وتدفع رسوم توصيل واحدة، وتتابع الطلب من لحظة قبوله حتى وصوله إلى بابك.

                    للتواصل: من شاشة المساعدة داخل التطبيق، أو على أرقام الدعم المعروضة فيها.
                    AR,
                'body_en' => <<<'EN'
                    CR7 Delivery is a food delivery platform in Gamasa. It brings the city's restaurants together in one place and delivers with its own fleet of captains.

                    You can order from up to three restaurants in a single basket, pay one delivery fee, and follow the order from the moment it is accepted until it reaches your door.

                    Contact us from the help screen inside the app, or on the support numbers shown there.
                    EN,
                'is_active' => true,
            ],
        ];
    }

    private function faqs(): array
    {
        return [
            [
                'category' => 'orders',
                'question_ar' => 'كيف أتابع طلبي؟',
                'question_en' => 'How do I track my order?',
                'answer_ar' => 'من «طلباتي» اختر الطلب الجاري. تظهر حالته لحظة بلحظة، ويظهر موقع الكابتن على الخريطة بعد استلامه الطلب من المطعم.',
                'answer_en' => 'Open the active order from “My orders”. Its status updates live, and the captain appears on the map once they have collected the order.',
            ],
            [
                'category' => 'orders',
                'question_ar' => 'هل يمكنني الطلب من أكثر من مطعم؟',
                'question_en' => 'Can I order from more than one restaurant?',
                'answer_ar' => 'نعم، حتى ثلاثة مطاعم في سلة واحدة ورسوم توصيل واحدة. قد تصل الطلبات في أوقات مختلفة لأن لكل مطعم وقت تحضير مختلفًا.',
                'answer_en' => 'Yes — up to three restaurants in one basket, with a single delivery fee. They may arrive at different times, since each restaurant has its own prep time.',
            ],
            [
                'category' => 'payment',
                'question_ar' => 'ما هي رسوم الخدمة؟',
                'question_en' => 'What is the service fee?',
                'answer_ar' => 'رسوم ثابتة تُضاف على كل طلب مقابل تشغيل المنصة، منفصلة عن قيمة الطعام وعن رسوم التوصيل، وتظهر في سطر مستقل داخل ملخّص الطلب.',
                'answer_en' => 'A fixed fee added to every order to run the platform. It is separate from the food total and from the delivery fee, and appears as its own line in the order summary.',
            ],
            [
                'category' => 'payment',
                'question_ar' => 'متى أدفع؟',
                'question_en' => 'When do I pay?',
                'answer_ar' => 'إذا اخترت الدفع نقدًا فتدفع للكابتن عند الاستلام. إذا اخترت التحويل فيُفتح الدفع بعد رد كل المطاعم على طلبك، ويظهر لك المبلغ النهائي والمهلة.',
                'answer_en' => 'If you chose cash, you pay the captain on delivery. If you chose a transfer, payment opens once every restaurant has answered — you then see the final amount and the deadline.',
            ],
            [
                'category' => 'payment',
                'question_ar' => 'كيف أرفع إثبات التحويل؟',
                'question_en' => 'How do I upload proof of transfer?',
                'answer_ar' => 'من شاشة الطلب اضغط «رفع إثبات الدفع»، وأرفق صورة إيصال التحويل ورقم العملية. تُراجَع خلال دقائق.',
                'answer_en' => 'From the order screen tap “Upload payment proof”, attach a screenshot of the receipt and the transaction number. It is reviewed within minutes.',
            ],
            [
                'category' => 'delivery',
                'question_ar' => 'ما هو كود التسليم؟',
                'question_en' => 'What is the delivery code?',
                'answer_ar' => 'رقم من أربعة أرقام يظهر في شاشة الطلب بعد استلام الكابتن للطعام. أعطه للكابتن عند الاستلام — لا يكتمل الطلب بدونه.',
                'answer_en' => 'A four-digit number shown on the order screen once the captain has collected the food. Give it to the captain on handover — the order cannot be completed without it.',
            ],
            [
                'category' => 'delivery',
                'question_ar' => 'كم تستغرق مدة التوصيل؟',
                'question_en' => 'How long does delivery take?',
                'answer_ar' => 'يعتمد على المطعم والمسافة. يظهر لك نطاق الوقت المتوقع قبل التأكيد، ويتحدّث بعد قبول المطعم للطلب.',
                'answer_en' => 'It depends on the restaurant and the distance. An expected range is shown before you confirm, and it updates once the restaurant accepts.',
            ],
            [
                'category' => 'account',
                'question_ar' => 'كيف ألغي طلبًا؟',
                'question_en' => 'How do I cancel an order?',
                'answer_ar' => 'من شاشة الطلب، ما دام المطعم لم يبدأ التحضير. بعد بدء التحضير تواصل مع خدمة العملاء.',
                'answer_en' => 'From the order screen, as long as the restaurant has not started preparing. After that, contact support.',
            ],
            [
                'category' => 'account',
                'question_ar' => 'كيف أصبح كابتن؟',
                'question_en' => 'How do I become a captain?',
                'answer_ar' => 'قدّم طلبًا من شاشة «انضم كابتن» في التطبيق. تُراجَع الطلبات خلال ‪48‬ ساعة، ويصلك اتصال لاستكمال الأوراق.',
                'answer_en' => 'Apply from the “Join as a captain” screen in the app. Applications are reviewed within 48 hours and you will be called to complete your paperwork.',
            ],
        ];
    }
}
