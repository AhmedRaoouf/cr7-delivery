<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-10-13 — `routes/web.php` was replaced on the server by one line, `Route::view('/',
 * 'landing')`, to put up the landing page. Every uploaded picture (1 375 of the 1 877 the
 * customer API hands out) and the four pages both store listings link to answered 404 until
 * the file was restored.
 *
 * Each route below is covered on its own elsewhere; this names them together, beside the
 * landing page, so the file cannot be traded for the page again.
 */
class PublicRoutesTest extends TestCase
{
    public function test_every_public_route_is_registered_beside_the_landing_page(): void
    {
        foreach ([
            'landing',
            'uploads.show',
            'images.resized',
            'share.restaurant',
            'share.product',
            'share.aasa',
            'legal.privacy',
            'legal.terms',
            'legal.delete-account',
            'legal.support',
        ] as $name) {
            $this->assertTrue(Route::has($name), "the public route «{$name}» is missing");
        }
    }

    public function test_the_root_is_the_landing_page_and_sets_no_cookie(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringContainsString('CR Delivery', $response->getContent());
        $this->assertStringContainsString('play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery', $response->getContent());
        $this->assertStringContainsString('apps.apple.com/app/id6815852436', $response->getContent());
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_an_uploaded_picture_is_served_beside_the_landing_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs('products', UploadedFile::fake()->image('dish.jpg'), 'dish.jpg');

        $this->get('/storage/products/dish.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/privacy')->assertOk();
    }
}
