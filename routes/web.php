<?php

use App\Http\Controllers\LegalPageController;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\FileUploader;
use App\Support\ImageResizer;
use App\Support\Locale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Batch 67 — none of the routes in this file needs a session, a cookie or a CSRF token.
 *
 * They are all public reads: an image, a share page, the App Links proof. They sat in the
 * `web` group, so every hit on `/` — the stock Laravel welcome page, 39 KB, printing
 * «Laravel v11.56.0 (PHP v8.3.33)» — wrote a session row and set `XSRF-TOKEN` and
 * `cr7_delivery_session` cookies, for scanners and crawlers alike. The `web` group
 * itself stays, for Telescope and the API docs, which do use its cookies.
 */
Route::withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
    AddQueuedCookiesToResponse::class,
    EncryptCookies::class,
])->group(function () {

    /*
     * The root says what this host is and nothing about what it runs on. `/up` is the health
     * check; this is only what a person who types the bare domain sees.
     */
    Route::get('/', fn () => response()->json(['name' => 'CR7 Delivery API', 'status' => 'ok']));

    /**
     * Serve the public upload disk directly.
     *
     * Every image the platform stores is saved as `<APP_URL>/storage/<path>` and served by
     * the web server through the `public/storage` symlink. On the live host that symlink is
     * not honoured — LiteSpeed hands the request to `index.php` instead, and Laravel answers
     * 404. The result was that seeded `/catalog/...` art worked while **every image any
     * operator ever uploaded** — dish photos, restaurant logos, marketing banners, complaint
     * attachments, avatars — rendered as a broken thumbnail in both dashboards and the app.
     *
     * A fallback route rather than a change of URL shape: the stored rows already hold
     * absolute `/storage/...` URLs, so a new prefix would have orphaned every one of them.
     * Where the symlink does work the web server answers first and this never runs.
     *
     * `where` pins the parameter to the upload folders and forbids `..`, so this cannot be
     * turned into an arbitrary-file reader for the private disk.
     */
    Route::get('/storage/{path}', function (string $path) {
        abort_if(str_contains($path, '..'), 404);

        /*
         * Batch 67 — pictures only, typed by their extension, never by their bytes.
         *
         * `$disk->response()` sniffed the MIME type from the file's contents, so anything
         * that reached the upload disk — an SVG stored as `.bin` before the uploader
         * refused them, a file some older path wrote — was served with whatever type its
         * bytes claimed: `image/svg+xml` or `text/html`, from the API's own origin, which
         * is stored XSS. Every public upload is written with one of these extensions
         * (`FileUploader::SAFE_IMAGE_EXTENSIONS`), so nothing legitimate is lost.
         */
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        abort_unless(array_key_exists($extension, $types), 404);

        $disk = Storage::disk(FileUploader::disk());

        abort_unless($disk->exists($path), 404);

        $response = $disk->response($path, null, [
            'Content-Type' => $types[$extension],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            $response->setPublic();
            $response->setMaxAge(31536000);
        }

        return $response;
    })->where('path', '[A-Za-z0-9/_\-\.]+')->name('uploads.show');

    /**
     * A picture at the size it will actually be drawn.
     *
     * «في التطبيق بياخد وقت كبير اوي علي ما يحمل الصور» — operator, 2026-09-09. A
     * restaurant logo drawn in a 42 dp circle was a 512 × 512 PNG of 370 KB, and a
     * cold home screen pulled about 3.5 MB across thirty requests off a host with
     * no CDN. `AppImage` already decoded at the drawn size; nothing shrank the
     * download.
     *
     * `/img/{width}/catalog/…` answers the same picture at one of
     * `ImageResizer::WIDTHS`, and **writes the file it just made to that exact
     * path** — so the second request for it is served by the web server off disk
     * and never reaches PHP. Where the file already exists this route never runs.
     *
     * The width is matched against the allowlist by the regex as well as inside
     * `ImageResizer`, so an unknown one is a 404 from the router rather than a PHP
     * boot. `where` on the path forbids `..` and pins it to the catalogue folders.
     */
    Route::get('/img/{width}/{path}', function (string $width, string $path) {
        $file = ImageResizer::make($path, (int) $width);

        abort_if($file === null, 404);

        // Typed by extension, never by sniffing the bytes (as `/storage/{path}` above): a
        // resize of an upload is served from the API's own origin.
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'];
        $type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

        abort_if($type === null, 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // A year: the URL carries a `?v=` fingerprint that changes with the
            // source, so a stale copy cannot outlive the picture it is of.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    })
        ->where('width', implode('|', ImageResizer::WIDTHS))
        // Batch 72 — beyond the catalogue: the media library's own art (`media/system`), the
        // shipped app and dashboard pictures, and the uploads disk (`storage/…`).
        ->where('path', '(?:catalog|media/system|shipped|storage)/[A-Za-z0-9/_\-\.]+') // = ImageResizer::ROUTE_PATTERN
        ->name('images.resized');

    /**
     * Where a shared link lands.
     *
     * `CUS-14`'s share sheet hands out `https://cr7-delivery.com/r/<id>`, and Android and
     * iOS intercept that for anybody who has the app — `public/.well-known/assetlinks.json`
     * and the `associated-domains` entitlement are what make the interception silent rather
     * than a chooser. Everybody else reaches this: the name, the picture, and a button that
     * fires the custom scheme.
     *
     * Without these two routes the share button was handing out a 404 to every person who
     * did not already have the app — which is most of the people a share is aimed at.
     *
     * The `og:` tags matter as much as the page: WhatsApp and Messenger render the preview
     * card from them, and a link with none is a bare blue URL nobody taps.
     */
    Route::get('/r/{restaurant}', function (Restaurant $restaurant) {
        abort_unless($restaurant->is_active, 404);

        $locale = Locale::current(request());

        // Arabic is the source language: a shop with no English tagline shows its Arabic
        // one rather than the generic line, which is what a blank `tagline_en` would
        // otherwise produce for every shop on the roster.
        $first = fn (?string ...$values) => collect($values)->first(fn ($v) => filled($v));

        return view('share', [
            'title' => $first($restaurant->{"name_{$locale}"}, $restaurant->name_ar),
            'subtitle' => $first(
                $restaurant->{"tagline_{$locale}"},
                $restaurant->tagline_ar,
                $restaurant->{"description_{$locale}"},
                $restaurant->description_ar,
            ) ?? 'اطلب الآن من CR7 Delivery',
            'image' => $restaurant->cover,
            'deepLink' => "cr7delivery://r/{$restaurant->id}",
        ]);
    })->whereNumber('restaurant')->name('share.restaurant');

    Route::get('/p/{product}', function (Product $product) {
        abort_unless($product->is_available, 404);

        $locale = Locale::current(request());
        $restaurant = $product->restaurant;

        $first = fn (?string ...$values) => collect($values)->first(fn ($v) => filled($v));

        return view('share', [
            'title' => $first($product->{"name_{$locale}"}, $product->name_ar),
            'subtitle' => $first(
                $restaurant?->{"name_{$locale}"},
                $restaurant?->name_ar,
            ) ?? 'اطلب الآن من CR7 Delivery',
            'image' => $product->image,
            'deepLink' => "cr7delivery://p/{$product->id}",
        ]);
    })->whereNumber('product')->name('share.product');

    /**
     * Batch 72 — the public legal pages the Google Play and App Store listings link to.
     *
     * Arabic then English on one URL (no language negotiation: the CDN strips `Vary`), no
     * external asset, cacheable for an hour. The text is `App\Support\Legal\LegalContent`.
     */
    foreach (['privacy', 'terms', 'delete-account', 'support'] as $legalPage) {
        Route::get('/'.$legalPage, [LegalPageController::class, 'show'])
            ->defaults('page', $legalPage)
            ->name('legal.'.$legalPage);
    }

    /**
     * The iOS half of the App Links proof.
     *
     * Served from a route rather than a file because it has no extension and must arrive as
     * `application/json`: dropped into `public/`, LiteSpeed answers it as
     * `application/octet-stream` and iOS silently ignores the whole domain — links keep
     * working, they just open Safari, and nothing anywhere says why.
     *
     * ⚠️ **This is only half of it.** The app must also carry
     * `com.apple.developer.associated-domains = applinks:cr7-delivery.com` in its release
     * entitlement, and that capability must exist on the App ID
     * `com.cr7delivery.cr7Delivery` for team `XB8YVBDAM7` — otherwise every device release
     * build fails to provision, which is exactly why `RunnerRelease.entitlements` does not
     * carry it yet. Until it does, an iPhone opens the shared link in Safari and lands on
     * the share page, whose «افتح في التطبيق» button uses the `cr7delivery://` scheme and
     * works today. This file being correct in advance costs nothing and means the switch is
     * one entitlement, not a server change too.
     */
    Route::get('/.well-known/apple-app-site-association', function () {
        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => ['XB8YVBDAM7.com.cr7delivery.cr7Delivery'],
                    'components' => [
                        ['/' => '/r/*', 'comment' => 'a shop'],
                        ['/' => '/p/*', 'comment' => 'a dish'],
                    ],
                ]],
            ],
        ])->header('Content-Type', 'application/json');
    })->name('share.aasa');

});
