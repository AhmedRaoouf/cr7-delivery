<?php

namespace App\Http\Controllers;

use App\Support\Legal\LegalContent;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Batch 72 — the public legal pages: `/privacy`, `/terms`, `/delete-account`, `/support`.
 *
 * Server-rendered HTML on the API's own host, for the store listings and for anyone who
 * cannot open the app. One page per URL carrying **both** languages (Arabic, then English) —
 * never negotiated by header, because the CDN in front of this host strips `Vary` and would
 * hand one language to everybody. The text is {@see LegalContent}; the only live data on a
 * page is the support contact block, read from the `support.*` settings.
 */
class LegalPageController extends Controller
{
    /** slug → [the content method, nav label ar, nav label en, meta description] */
    private const PAGES = [
        'privacy' => ['privacy', 'الخصوصية', 'Privacy',
            'سياسة الخصوصية لتطبيق CR7 Delivery: البيانات التي نجمعها ولماذا ومع من نشاركها وكيف تحذفها. · How CR7 Delivery collects, uses and shares your data, and how to delete it.'],
        'terms' => ['terms', 'الشروط', 'Terms',
            'الشروط والأحكام لاستخدام تطبيق CR7 Delivery. · The terms of using CR7 Delivery.'],
        'delete-account' => ['deleteAccount', 'حذف الحساب', 'Delete account',
            'كيف تحذف حسابك في CR7 Delivery من التطبيق أو بطلب مكتوب، وماذا يحدث لبياناتك. · How to delete your CR7 Delivery account, in the app or by written request, and what happens to your data.'],
        'support' => ['support', 'الدعم', 'Support',
            'طرق التواصل مع دعم CR7 Delivery. · How to reach CR7 Delivery support.'],
    ];

    public function show(Request $request, string $page): Response
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        [$method, , , $description] = self::PAGES[$page];
        $doc = LegalContent::{$method}();

        $html = view('legal.page', [
            'doc' => $doc,
            'path' => $page,
            'canonical' => url('/'.$page),
            'description' => $description,
            'updated' => LegalContent::UPDATED,
            'bodyAr' => LegalContent::html($doc['body_ar']),
            'bodyEn' => LegalContent::html($doc['body_en']),
            'nav' => array_map(fn (string $path, array $p) => ['path' => $path, 'ar' => $p[1], 'en' => $p[2]], array_keys(self::PAGES), self::PAGES),
            'channels' => $this->channels(),
        ])->render();

        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Nothing is loaded from anywhere: one inline stylesheet, no script, no image, no font.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);

        // Cacheable by anyone: the page is the same for every visitor, so no `Vary` is needed.
        // An hour is short enough that a changed support number reaches the page the same day.
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setEtag(md5($html));
        $response->isNotModified($request);

        return $response;
    }

    /** @return list<array{ar: string, en: string, value: string, href: ?string}> */
    private function channels(): array
    {
        $channels = [];
        $value = fn (string $key): string => trim((string) Settings::get($key, ''));

        if (($email = $value('support.email')) !== '') {
            $channels[] = ['ar' => 'البريد الإلكتروني', 'en' => 'Email', 'value' => $email,
                'href' => filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$email : null];
        }

        if (($whatsapp = $value('support.whatsapp')) !== '' && ($digits = $this->international($whatsapp)) !== null) {
            $channels[] = ['ar' => 'واتساب', 'en' => 'WhatsApp', 'value' => $whatsapp, 'href' => 'https://wa.me/'.$digits];
        }

        foreach (['support.phone' => ['الهاتف', 'Phone'], 'support.phone_alt' => ['هاتف آخر', 'Second phone']] as $key => [$ar, $en]) {
            if (($phone = $value($key)) !== '') {
                $digits = $this->international($phone);
                $channels[] = ['ar' => $ar, 'en' => $en, 'value' => $phone, 'href' => $digits !== null ? 'tel:+'.$digits : null];
            }
        }

        if (($hours = $value('support.hours_note')) !== '') {
            $channels[] = ['ar' => 'مواعيد الدعم', 'en' => 'Support hours', 'value' => $hours, 'href' => null];
        }

        return $channels;
    }

    /**
     * An Egyptian number as digits with the country code: `01012345678` → `201012345678`.
     * (`wa.me/01…` opens nothing — the customer app's support button shipped that way once.)
     */
    private function international(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '20'.substr($digits, 1);
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}
