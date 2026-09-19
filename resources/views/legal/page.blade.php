{{--
    A public legal page — privacy, terms, account deletion, support (batch 72).

    Google Play and the App Store need public URLs for these, and a person who has lost their
    phone needs one to ask for deletion, so it is served from the API's own host with no app,
    no session and no build step. Arabic first, then English, **on the same page** — the CDN in
    front of this host strips `Vary`, so a language chosen by header would be cached wrong.

    No external font, script, stylesheet or image: everything is inline, and the page's own
    CSP (set by the controller) allows nothing else.

    ⚠️ The text comes from `App\Support\Legal\LegalContent` and must be reviewed by the operator
    (and ideally a lawyer) before store submission.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $doc['title_ar'] }} · {{ $doc['title_en'] }} — CR7 Delivery</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="index,follow">
    <meta name="color-scheme" content="dark light">
    <link rel="canonical" href="{{ $canonical }}">

    <style>
        :root {
            color-scheme: dark light;
            --bg: #0a0a0b; --surface: #18181b; --border: #3f3f46;
            --text: #fafafa; --muted: #a1a1aa; --brand: #ffc300; --on-brand: #0a0a0b;
        }
        @media (prefers-color-scheme: light) {
            :root {
                --bg: #ffffff; --surface: #f4f4f5; --border: #d4d4d8;
                --text: #18181b; --muted: #52525b; --brand: #b58900; --on-brand: #0a0a0b;
            }
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 17px/1.75 "IBM Plex Sans Arabic", -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .wrap { max-width: 760px; margin: 0 auto; padding: 0 20px 48px; }
        header.top { border-bottom: 1px solid var(--border); background: var(--surface); }
        header.top .wrap { padding-bottom: 0; }
        .brand { display: inline-block; padding: 18px 0 6px; font-weight: 800; letter-spacing: 2px; color: var(--brand); text-decoration: none; }
        nav { display: flex; flex-wrap: wrap; gap: 4px 18px; padding-bottom: 14px; font-size: 15px; }
        nav a { color: var(--muted); text-decoration: none; padding: 4px 0; border-bottom: 2px solid transparent; }
        nav a:hover, nav a:focus-visible { color: var(--text); }
        nav a[aria-current="page"] { color: var(--brand); border-bottom-color: var(--brand); }
        .lang-jump { margin: 22px 0 0; font-size: 14px; }
        .lang-jump a { color: var(--brand); }
        .updated { color: var(--muted); font-size: 14px; margin: 6px 0 0; }
        section.doc { padding-top: 8px; }
        section.doc + section.doc { margin-top: 40px; padding-top: 32px; border-top: 1px solid var(--border); }
        h1 { font-size: 30px; line-height: 1.3; margin: 26px 0 4px; }
        h2 { font-size: 20px; line-height: 1.4; margin: 30px 0 6px; color: var(--brand); }
        p { margin: 0 0 14px; }
        ul, ol { margin: 0 0 14px; padding-inline-start: 24px; }
        li { margin: 0 0 6px; }
        a { color: var(--brand); }
        .contact { margin-top: 44px; padding: 20px; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); }
        .contact h2 { margin-top: 0; }
        .contact ul { list-style: none; padding: 0; margin: 0; }
        .contact li { display: flex; flex-wrap: wrap; gap: 4px 12px; }
        .contact .k { color: var(--muted); min-width: 9em; }
        .review { margin-top: 28px; padding: 12px 16px; border-radius: 12px; border: 1px dashed var(--border); color: var(--muted); font-size: 14px; }
        footer { margin-top: 36px; color: var(--muted); font-size: 14px; }
        footer a { color: var(--muted); }
        [dir="ltr"] { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        bdi, .ltr { unicode-bidi: isolate; }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="{{ url('/support') }}">CR7 DELIVERY</a>
            <nav aria-label="Legal / قانوني">
                @foreach ($nav as $item)
                    <a href="{{ url('/'.$item['path']) }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['ar'] }} · {{ $item['en'] }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="wrap">
        <p class="lang-jump"><a href="#en" hreflang="en">English version below ↓</a></p>

        <section class="doc" id="ar" lang="ar" dir="rtl">
            <h1>{{ $doc['title_ar'] }}</h1>
            <p class="updated">آخر تحديث: <bdi dir="ltr">{{ $updated }}</bdi></p>
            {!! $bodyAr !!}
        </section>

        <section class="doc" id="en" lang="en" dir="ltr">
            <h1>{{ $doc['title_en'] }}</h1>
            <p class="updated">Last updated: <bdi dir="ltr">{{ $updated }}</bdi></p>
            {!! $bodyEn !!}
        </section>

        <aside class="contact" aria-labelledby="contact-title">
            <h2 id="contact-title">تواصل معنا · Contact us</h2>
            @if ($channels !== [])
                <ul>
                    @foreach ($channels as $channel)
                        <li>
                            <span class="k">{{ $channel['ar'] }} · {{ $channel['en'] }}</span>
                            @if ($channel['href'])
                                <a href="{{ $channel['href'] }}" @if (! str_starts_with($channel['href'], 'mailto:') && ! str_starts_with($channel['href'], 'tel:')) rel="noopener noreferrer" @endif><bdi dir="ltr">{{ $channel['value'] }}</bdi></a>
                            @else
                                <span>{{ $channel['value'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p>من شاشة «المساعدة» داخل التطبيق. · From the "Help" screen inside the app.</p>
            @endif
        </aside>

        <footer>
            <p>
                <a href="{{ url('/privacy') }}">سياسة الخصوصية · Privacy policy</a> ·
                <a href="{{ url('/terms') }}">الشروط والأحكام · Terms</a> ·
                <a href="{{ url('/delete-account') }}">حذف الحساب · Delete account</a> ·
                <a href="{{ url('/support') }}">الدعم · Support</a>
            </p>
            <p>© CR7 Delivery</p>
        </footer>
    </main>
</body>
</html>
