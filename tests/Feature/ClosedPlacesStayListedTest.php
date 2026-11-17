<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * doc 108 — «لما المطعم يكون مقفول يعني عادي إنه هو موجود بس مغلق الآن. لأن في ناس كتير
 * بتخش وتقول لك المطعم مش موجود» (Ahmed Raouf, 2026-11-17).
 *
 * Every app up to 1.0.24 starts its filter sheet with «مفتوح الآن» on and sends
 * `filter[open_now]=1` on each home load, so a closed place was not on the list at all.
 * It stays on the list now, after every open one, and its card shows «مغلق الآن».
 */
class ClosedPlacesStayListedTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $closed;

    private Restaurant $open;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        // The closed one comes first in the operator's own order.
        $this->closed = $this->makeRestaurant('زهو');
        $this->open = $this->makeRestaurant('تكة');
        $this->closed->forceFill(['is_featured' => true, 'is_open_manual' => false])->save();
    }

    /** @return list<array{id:int, open:bool}> */
    private function list(array $query, ?string $version): array
    {
        $headers = ['X-App' => 'app'] + ($version ? ['X-App-Version' => $version] : []);

        return array_map(
            fn (array $row) => ['id' => $row['id'], 'open' => $row['is_open_now']],
            $this->withHeaders($headers)->getJson('/api/v1/restaurants?'.http_build_query($query))->assertOk()->json('data'),
        );
    }

    public function test_an_installed_app_sees_the_closed_place_after_the_open_one(): void
    {
        // Exactly what 1.0.24 sends untouched.
        $rows = $this->list(['filter' => ['open_now' => 1]], '1.0.24+31');

        $this->assertSame([
            ['id' => $this->open->id, 'open' => true],
            ['id' => $this->closed->id, 'open' => false],
        ], $rows);
    }

    public function test_a_current_app_that_chose_open_now_gets_only_open_places(): void
    {
        $this->assertSame([['id' => $this->open->id, 'open' => true]], $this->list(['filter' => ['open_now' => 1]], '1.0.25'));
        $this->assertCount(2, $this->list([], '1.0.25'));
    }

    public function test_the_two_answers_are_never_served_from_one_cache_entry(): void
    {
        $this->assertCount(2, $this->list(['filter' => ['open_now' => 1]], '1.0.24'));
        $this->assertCount(1, $this->list(['filter' => ['open_now' => 1]], '1.0.25'));
        $this->assertCount(2, $this->list(['filter' => ['open_now' => 1]], '1.0.24'));
    }

    public function test_a_place_outside_its_hours_counts_as_closed_too(): void
    {
        $this->closed->forceFill(['is_open_manual' => true, 'is_24_7' => false])->save();
        // Closed all day today (Cairo).
        $this->closed->openingHours()->create([
            'weekday' => now('Africa/Cairo')->dayOfWeek, 'is_closed' => true, 'is_24h' => false,
        ]);

        $this->assertSame(
            [$this->open->id, $this->closed->id],
            array_column($this->list([], null), 'id'),
        );
    }
}
