<?php

use App\Support\FileUploader;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

Route::get('/', function () {
    return view('welcome');
});

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

    $disk = Storage::disk(FileUploader::disk());

    abort_unless($disk->exists($path), 404);

    $response = $disk->response($path);

    if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
        $response->setPublic();
        $response->setMaxAge(31536000);
    }

    return $response;
})->where('path', '[A-Za-z0-9/_\-\.]+')->name('uploads.show');
