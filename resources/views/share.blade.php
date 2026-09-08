{{--
    The page a shared link lands on for anybody without the app.

    `CUS-14`'s share sheet hands out `https://cr7-delivery.com/r/<id>`. On a phone with
    the app installed Android and iOS intercept it and this is never rendered; on
    everything else — a desktop, a phone that has not installed it, a WhatsApp in-app
    browser that refuses to hand the link over — this is what the customer sees. Without
    it, sharing a shop meant sharing a 404.

    Deliberately one file with its own styles and no build step: it is served to people
    who do not have the app yet, and pulling a stylesheet or a font over a phone plan to
    show them a name and a button is the wrong trade.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} · CR7 Delivery</title>

    {{-- What WhatsApp, Facebook and Messages render in the preview card. The share
         sheet's own text is the fallback; these are what make it a picture. --}}
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $subtitle }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif

    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh;
            display: flex; align-items: center; justify-content: center;
            background: #0a0a0b; color: #fafafa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, sans-serif;
            padding: 24px;
        }
        .card { width: 100%; max-width: 420px; text-align: center; }
        .art {
            width: 100%; aspect-ratio: 4 / 3; object-fit: cover;
            border-radius: 20px; border: 1px solid #3f3f46; background: #18181b;
        }
        h1 { font-size: 24px; margin: 20px 0 6px; }
        p  { color: #a1a1aa; margin: 0 0 24px; line-height: 1.6; }
        a.cta {
            display: block; padding: 14px 20px; border-radius: 12px;
            background: #ffc300; color: #0a0a0b; font-weight: 700;
            text-decoration: none; margin-bottom: 10px;
        }
        a.ghost { display: block; padding: 12px; color: #a1a1aa; text-decoration: none; font-size: 14px; }
        .mark { font-weight: 800; letter-spacing: 2px; color: #ffc300; margin-bottom: 28px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">CR7 DELIVERY</div>

        @if ($image)
            <img class="art" src="{{ $image }}" alt="">
        @endif

        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>

        {{-- The custom scheme, not the https one: this page *is* the https one, and
             linking to itself would loop. A phone with the app installed opens it; a
             phone without shows nothing and the second link is what they use. --}}
        <a class="cta" href="{{ $deepLink }}">افتح في التطبيق</a>
        <a class="ghost" href="https://cr7-delivery.com">‏ليس لديك التطبيق؟ حمّله من هنا</a>
    </div>
</body>
</html>
