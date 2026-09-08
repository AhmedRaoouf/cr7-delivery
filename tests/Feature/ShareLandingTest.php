<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a shared link lands for somebody without the app.
 *
 * `CUS-14`'s share sheet hands out `https://cr7-delivery.com/r/<id>`. A phone with the
 * app installed never reaches this page — Android and iOS intercept the URL — so it is
 * exactly the people the share was aimed at who do, and before 2026-09-08 what they got
 * was a 404.
 */
class ShareLandingTest extends TestCase
{
    use RefreshDatabase;

    /** `TestCase::makeRestaurant` builds a complete row; this only adds what the page reads. */
    protected function shareableShop(bool $active = true): Restaurant
    {
        // A restaurant needs a city and a zone to exist at all.
        $this->seedLocation();
        $restaurant = $this->makeRestaurant('بوكس ع السريع');

        $restaurant->update([
            'tagline_ar' => 'في كل بوكس حكاية',
            'cover' => 'catalog/covers/boxs.jpg',
            'is_active' => $active,
        ]);

        return $restaurant->fresh();
    }

    public function test_a_shared_shop_renders_a_page_a_preview_card_can_read(): void
    {
        $restaurant = $this->shareableShop();

        $response = $this->get("/r/{$restaurant->id}")->assertOk();

        $response->assertSee('بوكس ع السريع', escape: false);
        $response->assertSee('في كل بوكس حكاية', escape: false);
        // The button that hands the link back to the app. Not the https form — this page
        // *is* the https form, and linking to itself would loop.
        $response->assertSee("cr7delivery://r/{$restaurant->id}", escape: false);
        // WhatsApp and Messenger render the card from these; without them a share is a
        // bare blue URL nobody taps.
        $response->assertSee('og:image', escape: false);
        $response->assertSee('boxs.jpg', escape: false);
    }

    public function test_a_shared_dish_names_the_shop_it_belongs_to(): void
    {
        $restaurant = $this->shareableShop();
        $product = $this->makeProduct($restaurant, 'برجر سماش', 130);

        $this->get("/p/{$product->id}")
            ->assertOk()
            ->assertSee('برجر سماش', escape: false)
            ->assertSee('بوكس ع السريع', escape: false)
            ->assertSee("cr7delivery://p/{$product->id}", escape: false);
    }

    public function test_a_delisted_shop_is_a_404_rather_than_a_page_about_nothing(): void
    {
        $restaurant = $this->shareableShop(active: false);

        $this->get("/r/{$restaurant->id}")->assertNotFound();
        $this->get('/r/999999')->assertNotFound();
    }

    /**
     * The file Android reads to stop showing a chooser.
     *
     * It carries the **release** signing fingerprint, which is a public value by
     * design — it is published to the whole internet from this path. Asserted here
     * because a typo in it fails silently: links keep working, they just open the
     * browser instead of the app, and nobody notices for a month.
     */
    public function test_the_app_links_proof_is_served_and_names_the_release_key(): void
    {
        $path = public_path('.well-known/assetlinks.json');

        $this->assertFileExists($path);

        $proof = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('com.cr7delivery.cr7_delivery', $proof[0]['target']['package_name']);
        $this->assertSame(
            ['delegate_permission/common.handle_all_urls'],
            $proof[0]['relation'],
        );

        $fingerprint = $proof[0]['target']['sha256_cert_fingerprints'][0];
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $fingerprint);
    }
}
