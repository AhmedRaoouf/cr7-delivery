<?php

namespace Tests\Feature\Lists;

use App\Enums\OrderStatus;
use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — ADMIN-LISTS.md §5: the public reads the customer home opens on are built
 * once, revalidated with `ETag`/`304`, and retired by the writes they depend on.
 */
class CatalogHttpCacheTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        $this->place = $this->makeRestaurant('سندوتش عمر');
    }

    public function test_config_is_tagged_stored_and_answered_304_without_a_body(): void
    {
        $first = $this->getJson('/api/v1/config')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertMatchesRegularExpression('#^W/"[0-9a-f]{32}"$#', (string) $etag);
        $this->assertSame('MISS', $first->headers->get('X-Cache'));
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=30', (string) $first->headers->get('Cache-Control'));
        $this->assertContains('Accept-Language', $first->headers->all('Vary'));
        $this->assertContains('Authorization', $first->headers->all('Vary'));

        $revalidated = $this->getJson('/api/v1/config', ['If-None-Match' => $etag]);

        $revalidated->assertStatus(304);
        $this->assertSame('', $revalidated->getContent());
        $this->assertSame($etag, $revalidated->headers->get('ETag'));
        $this->assertSame('HIT', $revalidated->headers->get('X-Cache'));
        $this->assertContains('Accept-Language', $revalidated->headers->all('Vary'));

        // A tag that no longer matches is a full answer.
        $this->getJson('/api/v1/config', ['If-None-Match' => 'W/"stale"'])->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_hit_does_not_run_the_controller_and_a_setting_saved_retires_it(): void
    {
        Settings::set('support.phone', '01000000001');
        $this->getJson('/api/v1/config')->assertJsonPath('data.support.phone', '01000000001');

        // Out-of-band SQL fires no model event: the stored answer stands until its TTL —
        // which is how we know the second read never reached the database.
        DB::table('settings')->where('key', 'support.phone')->update(['value' => json_encode('01000000002')]);
        $this->getJson('/api/v1/config')->assertJsonPath('data.support.phone', '01000000001');

        // Saved the way the panel saves it: the very next read is rebuilt.
        Settings::set('support.phone', '01000000003');
        $this->getJson('/api/v1/config')->assertJsonPath('data.support.phone', '01000000003');
    }

    public function test_a_signed_in_config_is_private_and_follows_the_account(): void
    {
        Settings::set(FirstOrderPerk::SETTING, true);

        $newcomer = $this->customerNamed('منى', '01022220002');
        $returning = $this->customerNamed('أحمد', '01022220001');
        $this->orderFor($this->place, ['status' => OrderStatus::Delivered->value], $returning);

        $guest = $this->getJson('/api/v1/config')->assertOk();
        $this->assertFalse($guest->json('data.first_order.free_delivery_eligible'));

        Sanctum::actingAs($newcomer);
        $new = $this->withHeader('X-App', 'app')->getJson('/api/v1/config')->assertOk();
        $this->assertStringContainsString('private', (string) $new->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $new->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $new->headers->get('Cache-Control'));

        Sanctum::actingAs($returning);
        $old = $this->withHeader('X-App', 'app')->getJson('/api/v1/config')->assertOk();

        // Three audiences, three answers — never one stored body handed to all of them.
        $this->assertTrue($new->json('data.first_order.free_delivery_eligible'));
        $this->assertFalse($old->json('data.first_order.free_delivery_eligible'));
        $this->assertNotSame($new->headers->get('ETag'), $guest->headers->get('ETag'));
    }

    public function test_the_language_is_part_of_the_key(): void
    {
        $this->place->update(['name_en' => 'Omar Sandwich']);

        // `GET /restaurants` names a place in the request's language.
        $ar = $this->getJson('/api/v1/restaurants', ['Accept-Language' => 'ar'])->assertOk();
        $en = $this->getJson('/api/v1/restaurants', ['Accept-Language' => 'en'])->assertOk();

        $this->assertSame('سندوتش عمر', $ar->json('data.0.name'));
        $this->assertSame('Omar Sandwich', $en->json('data.0.name'));
        $this->assertSame('MISS', $en->headers->get('X-Cache'));
        $this->assertSame('HIT', $this->getJson('/api/v1/restaurants', ['Accept-Language' => 'en'])->headers->get('X-Cache'));
        $this->assertSame('سندوتش عمر', $this->getJson('/api/v1/restaurants', ['Accept-Language' => 'ar'])->json('data.0.name'));
    }

    public function test_a_catalogue_write_retires_the_stored_answers_at_once(): void
    {
        $this->assertCount(0, $this->getJson('/api/v1/cuisines')->assertOk()->json('data'));
        $this->assertSame('HIT', $this->getJson('/api/v1/cuisines')->headers->get('X-Cache'));

        Cuisine::query()->create(['name_ar' => 'مشويات', 'name_en' => 'Grill', 'is_active' => true]);

        $after = $this->getJson('/api/v1/cuisines')->assertOk();
        $this->assertSame('MISS', $after->headers->get('X-Cache'));
        $this->assertCount(1, $after->json('data'));

        // A place switched off leaves the list on the next read.
        $this->assertCount(1, $this->getJson('/api/v1/restaurants')->json('data'));
        $this->place->update(['is_active' => false]);
        $this->assertCount(0, $this->getJson('/api/v1/restaurants')->json('data'));
    }

    public function test_only_bounded_requests_are_stored(): void
    {
        $known = ['lat' => (string) $this->zone->center_lat, 'lng' => (string) $this->zone->center_lng];

        $this->assertSame('MISS', $this->getJson('/api/v1/restaurants?'.http_build_query($known + ['per_page' => 16]))->headers->get('X-Cache'));
        $this->assertSame('HIT', $this->getJson('/api/v1/restaurants?'.http_build_query($known + ['per_page' => 16]))->headers->get('X-Cache'));

        foreach ([
            'a point that is not one of ours' => ['lat' => '31.123456', 'lng' => '31.654321'],
            'a search term' => ['search' => 'عمر'],
            'a page nobody polls' => ['page' => 7],
            'a parameter no client sends' => ['utm' => 'x'],
        ] as $why => $query) {
            $response = $this->getJson('/api/v1/restaurants?'.http_build_query($query))->assertOk();

            $this->assertSame('BYPASS', $response->headers->get('X-Cache'), $why);
            // Still tagged, so a repeat still costs no body.
            $this->getJson('/api/v1/restaurants?'.http_build_query($query), ['If-None-Match' => $response->headers->get('ETag')])
                ->assertStatus(304);
        }
    }

    public function test_an_error_is_neither_stored_nor_tagged(): void
    {
        $this->place->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/restaurants/{$this->place->id}")->assertNotFound();

        $this->assertNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('X-Cache'));
    }

    public function test_the_prune_command_deletes_only_expired_rows_of_a_database_store(): void
    {
        $this->assertTrue(Schema::hasTable('cache'));
        config(['cache.default' => 'database']);

        DB::table('cache')->insert([
            ['key' => 'gone', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'kept', 'value' => 's:1:"y";', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('cr7:prune-cache')->assertSuccessful();

        $this->assertSame(['kept'], DB::table('cache')->pluck('key')->all());
    }
}
