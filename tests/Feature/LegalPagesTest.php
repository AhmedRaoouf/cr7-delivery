<?php

namespace Tests\Feature;

use App\Actions\Auth\DeleteAccountAction;
use App\Models\StaticPage;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Auth\AccountErasure;
use App\Support\Legal\LegalContent;
use App\Support\Settings;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Batch 72 — the public legal pages the store listings link to, and the in-app copy that
 * shares their text.
 *
 * ⚠️ These tests prove the pages are served, bilingual, self-contained and consistent with
 * what the code does. They cannot prove the *legal* text is right: that is the operator's
 * review before store submission.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['privacy', 'terms', 'delete-account', 'support'];

    // ── the four routes ─────────────────────────────────────────────────────────

    public function test_every_page_is_served_as_html_with_arabic_first_then_english(): void
    {
        foreach (self::PAGES as $page) {
            $response = $this->get('/'.$page)->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'), $page);
            $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html, $page);
            $this->assertStringContainsString('<section class="doc" id="ar" lang="ar" dir="rtl">', $html, $page);
            $this->assertStringContainsString('<section class="doc" id="en" lang="en" dir="ltr">', $html, $page);
            $this->assertLessThan(strpos($html, 'id="en"'), strpos($html, 'id="ar"'), "{$page}: Arabic comes first");
            $this->assertStringContainsString('<title>', $html);
            $this->assertStringContainsString('name="description"', $html);
            $this->assertStringContainsString('rel="canonical" href="'.url('/'.$page).'"', $html);
            $this->assertStringContainsString('2026-09-19', $html, "{$page}: last updated");
        }
    }

    public function test_titles_and_key_phrases_are_present_in_both_languages(): void
    {
        $expect = [
            'privacy' => ['سياسة الخصوصية', 'Privacy policy', 'الموقع الدقيق', 'precise location', 'رخصة القيادة', 'driving licence', 'الرقم القومي', 'national ID'],
            'terms' => ['الشروط والأحكام', 'Terms and conditions', 'فودافون كاش', 'Vodafone Cash', 'كود التسليم', 'delivery code'],
            'delete-account' => ['حذف الحساب', 'Delete your account', 'مستخدم محذوف', 'Deleted user', 'كيف نتحقق من هويتك', 'How we verify it is you'],
            'support' => ['الدعم', 'Support', 'صفحة حذف الحساب', 'Delete account page'],
        ];

        foreach ($expect as $page => $phrases) {
            $html = $this->get('/'.$page)->assertOk()->getContent();

            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $html, "{$page}: {$phrase}");
            }
        }
    }

    public function test_the_privacy_policy_names_what_the_app_really_collects_and_who_processes_it(): void
    {
        $html = $this->get('/privacy')->assertOk()->getContent();
        $text = strip_tags($html);

        foreach (['Firebase Cloud Messaging', 'Pusher', 'Mapbox', 'OpenFreeMap', 'ZADX', 'FCM', 'HTTPS', 'Vodafone Cash', 'InstaPay', 'background'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }

        // jsDelivr is not used by the app at runtime and must not be listed.
        $this->assertStringNotContainsStringIgnoringCase('jsdelivr', $html);
        $this->assertStringNotContainsStringIgnoringCase('google analytics', $html);

        // No card data, and the policy says so in both languages.
        $this->assertStringContainsString('بطاقة مصرفية', $text);
        $this->assertStringContainsString('bank-card', $text);

        // Numbers in Arabic prose are LTR isolates.
        $this->assertStringContainsString("\u{202A}16\u{202C}", $html);
    }

    public function test_the_pages_load_nothing_from_anywhere_else(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->get('/'.$page)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('/<script/i', $html, $page);
            $this->assertDoesNotMatchRegularExpression('/<link[^>]+stylesheet/i', $html, $page);
            $this->assertDoesNotMatchRegularExpression('/@import|@font-face|<img|<iframe|<video|<audio|<object|<embed/i', $html, $page);
            $this->assertDoesNotMatchRegularExpression('/\ssrc\s*=/i', $html, $page);
            $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?https?:/i', $html, $page);

            // Every absolute URL is on this host (with no contact set there is nothing else).
            preg_match_all('#https?://[^\s"\'<>)]+#i', $html, $urls);
            $host = parse_url(config('app.url'), PHP_URL_HOST);

            foreach ($urls[0] as $url) {
                $this->assertSame($host, parse_url($url, PHP_URL_HOST), "{$page}: {$url}");
            }
        }
    }

    public function test_the_response_headers_are_cacheable_and_safe(): void
    {
        $response = $this->get('/privacy')->assertOk();

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Vary'), 'no language negotiation: nothing varies');
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("style-src 'unsafe-inline'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEmpty($response->headers->getCookies(), 'a public page starts no session');

        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get('/privacy', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_an_unknown_legal_page_is_a_404(): void
    {
        $this->get('/legal')->assertNotFound();
        $this->get('/privacy/extra')->assertNotFound();
    }

    // ── the contact block ───────────────────────────────────────────────────────

    public function test_the_support_contact_comes_from_the_settings_and_is_escaped(): void
    {
        Settings::set('support.email', 'help@example.com', 'support', true);
        Settings::set('support.whatsapp', '01012345678', 'support', true);
        Settings::set('support.phone', '0101 234 5679', 'support', true);
        Settings::set('support.hours_note', 'يوميًا <b>9</b> - 5', 'support', true);

        foreach (self::PAGES as $page) {
            $html = $this->get('/'.$page)->assertOk()->getContent();

            $this->assertStringContainsString('href="mailto:help@example.com"', $html, $page);
            $this->assertStringContainsString('href="https://wa.me/201012345678"', $html, $page);
            $this->assertStringContainsString('href="tel:+20101234567', $html, $page);
            $this->assertStringContainsString('&lt;b&gt;9&lt;/b&gt;', $html, 'settings text is escaped');
            $this->assertStringNotContainsString('<b>9</b>', $html);
        }
    }

    public function test_a_missing_contact_falls_back_to_the_apps_help_screen(): void
    {
        $html = $this->get('/support')->assertOk()->getContent();

        $this->assertStringContainsString('شاشة «المساعدة» داخل التطبيق', $html);
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }

    // ── the same text in the app ────────────────────────────────────────────────

    public function test_pages_privacy_and_terms_carry_the_same_text_in_the_same_shape(): void
    {
        $this->seed(ContentSeeder::class);

        foreach (['privacy' => LegalContent::privacy(), 'terms' => LegalContent::terms()] as $slug => $doc) {
            $data = $this->getJson("/api/v1/pages/{$slug}")->assertOk()->assertJsonPath('success', true)->json('data');

            $this->assertSame($doc['title_ar'], $data['title_ar']);
            $this->assertSame($doc['title_en'], $data['title_en']);
            $this->assertSame($doc['body_ar'], $data['body_ar']);
            $this->assertSame($doc['body_en'], $data['body_en']);
            $this->assertTrue($data['is_active']);
            $this->assertSame($slug, $data['slug']);
        }

        // The app splits a body on blank lines and shows each block: every block is non-empty.
        $blocks = preg_split('/\n\s*\n/', LegalContent::privacy()['body_ar']);
        $this->assertGreaterThan(8, count($blocks));
        $this->assertNotContains('', array_map('trim', $blocks));
    }

    public function test_content_sync_legal_rewrites_only_terms_and_privacy(): void
    {
        StaticPage::query()->create(['slug' => 'privacy', 'title_ar' => 'قديم', 'title_en' => 'old', 'body_ar' => 'قديم', 'body_en' => 'old', 'is_active' => true]);
        StaticPage::query()->create(['slug' => 'about', 'title_ar' => 'عنّا', 'title_en' => 'About', 'body_ar' => 'نص', 'body_en' => 'text', 'is_active' => true]);

        $this->artisan('content:sync-legal')->assertSuccessful();

        $this->assertSame(LegalContent::privacy()['body_en'], StaticPage::query()->where('slug', 'privacy')->value('body_en'));
        $this->assertNotNull(StaticPage::query()->where('slug', 'terms')->first());
        $this->assertSame('text', StaticPage::query()->where('slug', 'about')->value('body_en'), 'about is untouched');

        // And a second run finds nothing to change.
        Artisan::call('content:sync-legal');
        $this->assertStringContainsString('already current', Artisan::output());
    }

    // ── the text must describe what the code does ───────────────────────────────

    public function test_the_deletion_text_matches_what_the_delete_action_does(): void
    {
        $this->seedLocation();
        $user = $this->makeCustomer();
        $user->update(['email' => 'someone@example.com']);
        $this->makeAddress($user);
        $user->deviceTokens()->create(['token' => 't', 'platform' => 'android', 'app' => 'customer']);
        $id = $user->id;

        app(DeleteAccountAction::class)->execute($user, null);

        $row = User::withTrashed()->find($id);
        $page = LegalContent::deleteAccount();
        $policy = LegalContent::privacy();

        // «Your name is replaced with "Deleted user"» — the very string the action writes.
        $this->assertSame(AccountErasure::DELETED_NAME, $row->name);
        foreach ([$page['body_ar'], $policy['body_ar']] as $text) {
            $this->assertStringContainsString('«'.AccountErasure::DELETED_NAME.'»', $text);
        }
        foreach ([$page['body_en'], $policy['body_en']] as $text) {
            $this->assertStringContainsString('"Deleted user"', $text);
        }

        // What the text says is erased at once really is: the phone and email are anonymous, the
        // account is closed, tokens and saved addresses are gone (the soft-deleted ones too).
        $this->assertStringStartsWith('deleted_', $row->phone);
        $this->assertStringEndsWith('@deleted.local', $row->email);
        $this->assertFalse((bool) $row->is_active);
        $this->assertTrue($row->trashed());
        $this->assertSame(0, $row->deviceTokens()->count());
        $this->assertSame(0, UserAddress::withTrashed()->where('user_id', $id)->count());

        // The old caveat is gone, and every erased item the text names is one the action erases
        // (each is asserted against the database in AccountDeletionTest).
        $this->assertStringNotContainsString('لا تُمحى تلقائيًا', $page['body_ar'].$policy['body_ar']);
        $this->assertStringNotContainsString('not erased automatically', $page['body_en'].$policy['body_en']);
        foreach (['عناوينك المحفوظة', 'مفضّلتك', 'الصور المرفقة بشكاواك', 'رموز الإشعارات', 'إيصالات التحويل'] as $phrase) {
            $this->assertStringContainsString($phrase, $page['body_ar'], $phrase);
        }
        foreach (['saved addresses', 'favourites', 'photos attached to your complaints', 'push tokens', 'receipt images'] as $phrase) {
            $this->assertStringContainsString($phrase, $page['body_en'], $phrase);
        }

        // The 30 days are stated only for the written request, which is a manual process.
        $this->assertStringContainsString('يدويًا', $page['body_ar']);
        $this->assertStringContainsString('by hand', $page['body_en']);
        $this->assertStringContainsString('طلب جارٍ', $page['body_ar']);
        $this->assertStringContainsString('in progress', $page['body_en']);
    }

    // ── the renderer ────────────────────────────────────────────────────────────

    public function test_the_renderer_escapes_everything_and_understands_headings_bullets_and_steps(): void
    {
        $html = LegalContent::html("## <script>alert(1)</script>\n\nA <b>line</b>\nsecond\n\n- one & two\n- three\n\n1. first\n2. second\n\nText\n- mixed");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('<h2>&lt;script&gt;alert(1)&lt;/script&gt;</h2>', $html);
        $this->assertStringContainsString('<p>A &lt;b&gt;line&lt;/b&gt;<br>second</p>', $html);
        $this->assertStringContainsString('<ul><li>one &amp; two</li><li>three</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<p>Text</p><ul><li>mixed</li></ul>', $html);
    }

    public function test_every_stored_body_renders_without_leftover_markers(): void
    {
        foreach ([LegalContent::privacy(), LegalContent::terms(), LegalContent::deleteAccount(), LegalContent::support()] as $doc) {
            foreach (['body_ar', 'body_en'] as $key) {
                $html = LegalContent::html($doc[$key]);

                $this->assertStringNotContainsString('[[', $doc[$key], "{$doc['slug']} {$key}: an unresolved number marker");
                $this->assertStringNotContainsString('## ', strip_tags($html), "{$doc['slug']} {$key}: an unrendered heading");
                $this->assertStringContainsString('<h2>', $html);
            }
        }
    }
}
