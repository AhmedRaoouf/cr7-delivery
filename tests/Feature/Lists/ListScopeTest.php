<?php

namespace Tests\Feature\Lists;

use App\Models\Restaurant;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — D-50…D-52 / D-141 under the new filters (ADMIN-LISTS.md §0.6).
 *
 * Every filter, every search and every sort is AND-ed inside `AdminScope`. The risk is
 * specific: a search is an OR across columns and relations, and one `orWhere` written
 * outside its group reads «(in scope AND name = x) OR phone like y» — every row in the
 * platform whose phone matches, whoever's city it is in. So each list is asked, by an
 * operator scoped to the second city, for a row in the first one: by its id in a filter,
 * and by its name, its phone and its number in the search box.
 */
class ListScopeTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $mine;

    private Restaurant $theirs;

    private User $scoped;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        // `theirs` stays in Gamasa; `mine` is in the city the operator is scoped to.
        $this->theirs = $this->makeRestaurant('كنج بروست');
        $this->mine = $this->moveToOtherCity($this->makeRestaurant('سندوتش عمر'));
        $this->scoped = $this->cityAdmin($this->otherCity);
    }

    public function test_a_filter_naming_an_out_of_scope_row_answers_an_empty_page(): void
    {
        $hidden = $this->orderFor($this->theirs, ['order_number' => 'CR7-9911-1']);
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $hidden->update(['driver_id' => $captain->id]);
        $this->complaintAbout($hidden);
        Settlement::query()->create(['party_type' => 'restaurant', 'party_id' => $this->theirs->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => 'pending']);

        $this->actingAsAdmin($this->scoped);

        foreach ([
            ['/api/v1/admin/orders', ['restaurant_id' => $this->theirs->id]],
            ['/api/v1/admin/orders', ['driver_id' => $captain->id]],
            ['/api/v1/admin/orders', ['city_id' => $this->city->id]],
            ['/api/v1/admin/restaurants', ['city_id' => $this->city->id]],
            ['/api/v1/admin/drivers', ['zone_id' => $this->zone->id]],
            ['/api/v1/admin/complaints', ['restaurant_id' => $this->theirs->id]],
            ['/api/v1/admin/settlements', ['restaurant_id' => $this->theirs->id]],
            ['/api/v1/admin/payments', ['city_id' => $this->city->id]],
            ['/api/v1/admin/lookups/restaurants', ['city_id' => $this->city->id]],
        ] as [$path, $filter]) {
            $this->assertSame([], $this->idsOf($path, ['filter' => $filter]), $path.' '.json_encode($filter));
        }
    }

    public function test_the_search_box_never_reaches_past_the_scope(): void
    {
        $ahmed = $this->customerNamed('أحمد الغريب', '01099990001', $this->city);
        $hidden = $this->orderFor($this->theirs, ['order_number' => 'CR7-9911-1', 'customer_name_snapshot' => 'أحمد الغريب', 'delivery_contact_phone' => '01099990001'], $ahmed);
        $this->complaintAbout($hidden, ['ticket_number' => 'CMP-HIDDEN']);
        $this->captainNamed('كابتن الغريب', '01099990002');

        $visible = $this->orderFor($this->mine, ['order_number' => 'CR7-1234-1', 'customer_name_snapshot' => 'منى']);

        $terms = ['الغريب', '0999900', 'CR7-9911', (string) $hidden->id, 'CMP-HIDDEN', 'بروست'];
        $paths = ['/api/v1/admin/orders', '/api/v1/admin/customers', '/api/v1/admin/drivers', '/api/v1/admin/complaints', '/api/v1/admin/restaurants', '/api/v1/admin/payments', '/api/v1/admin/lookups/restaurants', '/api/v1/admin/lookups/drivers'];

        // The terms do reach those rows — for an operator whose scope holds them.
        $this->actingAsAdmin($this->globalAdmin());
        $this->assertContains($hidden->id, $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => 'الغريب']]));
        $this->assertContains($hidden->id, $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '0999900']]));

        $this->actingAsAdmin($this->scoped);

        foreach ($paths as $path) {
            $inScope = $this->idsOf($path, ['per_page' => 100]);

            foreach ($terms as $term) {
                // Every row a scoped search answers is one the unfiltered scoped list holds.
                $this->assertEmpty(
                    array_diff($this->idsOf($path, ['filter' => ['search' => $term]]), $inScope),
                    "{$path} «{$term}» reached past the scope",
                );
            }
        }

        $this->assertNotContains($hidden->id, $this->idsOf('/api/v1/admin/orders', ['per_page' => 100]));

        // And the operator's own rows are still found.
        $this->assertSame([$visible->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '1234']]));
    }
}
