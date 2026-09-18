<?php

namespace Tests\Feature\Lists;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — ADMIN-LISTS.md §0, the rules every list shares, asserted on `/admin/orders`
 * (the list with the most filters) and spot-checked elsewhere.
 */
class ListConventionsTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        $this->place = $this->makeRestaurant('سندوتش عمر');
        $this->actingAsAdmin($this->globalAdmin());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function malformed(): array
    {
        return [
            'unknown enum' => ['/api/v1/admin/orders', ['filter' => ['status' => 'shipped']], 'filter.status'],
            'one bad value in a list' => ['/api/v1/admin/orders', ['filter' => ['status' => 'pending,shipped']], 'filter.status'],
            'id that is not a number' => ['/api/v1/admin/orders', ['filter' => ['restaurant_id' => 'abc']], 'filter.restaurant_id'],
            'id zero' => ['/api/v1/admin/restaurants', ['filter' => ['city_id' => '0']], 'filter.city_id'],
            'bool that is not a bool' => ['/api/v1/admin/drivers', ['filter' => ['is_online' => 'maybe']], 'filter.is_online'],
            'date in the wrong shape' => ['/api/v1/admin/orders', ['filter' => ['date_from' => '18/09/2026']], 'filter.date_from'],
            'date that does not exist' => ['/api/v1/admin/complaints', ['filter' => ['date_to' => '2026-02-31']], 'filter.date_to'],
            'reversed range' => ['/api/v1/admin/payments', ['filter' => ['date_from' => '2026-09-18', 'date_to' => '2026-09-01']], 'filter.date_from'],
            'bare spelling is validated too' => ['/api/v1/admin/customers', ['is_active' => 'nope'], 'filter.is_active'],
            'report date, which used to be a 500' => ['/api/v1/admin/reports/restaurants', ['filter' => ['date_from' => 'yesterday']], 'filter.date_from'],
            'dashboard date, which used to be a 500' => ['/api/v1/admin/dashboard/summary', ['filter' => ['date_from' => 'x']], 'filter.date_from'],
        ];
    }

    /** @param  array<string, mixed>  $query */
    #[DataProvider('malformed')]
    public function test_a_malformed_value_is_a_422_in_the_house_envelope(string $path, array $query, string $key): void
    {
        $this->getJson($this->listUrl($path, $query))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => [$key]]);
    }

    public function test_an_unknown_filter_key_is_ignored_and_all_means_no_filter(): void
    {
        $this->orderFor($this->place, ['status' => 'pending']);
        $this->orderFor($this->place, ['status' => 'delivered']);

        $this->assertCount(2, $this->idsOf('/api/v1/admin/orders', ['filter' => ['colour' => 'blue']]));
        $this->assertCount(2, $this->idsOf('/api/v1/admin/orders', ['filter' => ['status' => 'all']]));
        $this->assertCount(2, $this->idsOf('/api/v1/admin/orders', ['filter' => ['status' => '']]));
    }

    public function test_an_enum_takes_a_comma_list_or_an_array(): void
    {
        $pending = $this->orderFor($this->place, ['status' => 'pending']);
        $preparing = $this->orderFor($this->place, ['status' => 'preparing']);
        $this->orderFor($this->place, ['status' => 'delivered']);

        $expected = [$pending->id, $preparing->id];
        sort($expected);

        $comma = $this->idsOf('/api/v1/admin/orders', ['filter' => ['status' => 'pending,preparing']]);
        $array = $this->idsOf('/api/v1/admin/orders', ['filter' => ['status' => ['pending', 'preparing']]]);
        sort($comma);
        sort($array);

        $this->assertSame($expected, $comma);
        $this->assertSame($expected, $array);
    }

    public function test_the_documented_spelling_wins_over_the_bare_one(): void
    {
        $pending = $this->orderFor($this->place, ['status' => 'pending']);
        $this->orderFor($this->place, ['status' => 'delivered']);

        $this->assertSame([$pending->id], $this->idsOf('/api/v1/admin/orders', ['status' => 'pending']));
        $this->assertSame([$pending->id], $this->idsOf('/api/v1/admin/orders', ['status' => 'delivered', 'filter' => ['status' => 'pending']]));
    }

    public function test_per_page_is_clamped_and_meta_is_complete(): void
    {
        foreach (range(1, 3) as $i) {
            $this->orderFor($this->place);
        }

        $this->list('/api/v1/admin/orders', ['per_page' => 500])
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.sort', '-created_at');

        $this->list('/api/v1/admin/orders', ['per_page' => 0])->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 3);
        $this->list('/api/v1/admin/orders', ['per_page' => 'lots'])->assertJsonPath('meta.per_page', 20);
        // The endpoint's own default when absent.
        $this->list('/api/v1/admin/audit-logs')->assertJsonPath('meta.per_page', 30);
    }

    public function test_sort_is_whitelisted_and_an_unknown_one_falls_back_to_the_default(): void
    {
        $cheap = $this->orderFor($this->place, ['total' => 50]);
        $dear = $this->orderFor($this->place, ['total' => 500]);
        $middle = $this->orderFor($this->place, ['total' => 150]);

        $this->assertSame([$dear->id, $middle->id, $cheap->id], $this->idsOf('/api/v1/admin/orders', ['sort' => '-total']));
        $this->assertSame([$cheap->id, $middle->id, $dear->id], $this->idsOf('/api/v1/admin/orders', ['sort' => 'total']));

        // Not an error, not a SQL column the caller picked: the default.
        $this->list('/api/v1/admin/orders', ['sort' => 'password'])->assertJsonPath('meta.sort', '-created_at');
        $this->list('/api/v1/admin/orders', ['sort' => '-id; drop table orders'])->assertJsonPath('meta.sort', '-created_at');
    }

    public function test_a_date_range_is_a_cairo_day_not_a_utc_one(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00');

        // 23:30 UTC on the 17th is 02:30 on the 18th in Cairo — the 18th's first order.
        $early = $this->orderFor($this->place, ['created_at' => '2026-09-17 23:30:00']);
        // 21:30 UTC on the 18th is 00:30 on the 19th in Cairo — not the 18th's.
        $late = $this->orderFor($this->place, ['created_at' => '2026-09-18 21:30:00']);

        $this->assertSame([$early->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['date_from' => '2026-09-18', 'date_to' => '2026-09-18']]));
        $this->assertSame([$late->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['date_from' => '2026-09-19']]));

        Carbon::setTestNow();
    }

    public function test_the_search_box_folds_arabic_reads_any_phone_spelling_and_matches_refs_and_ids(): void
    {
        $ahmed = $this->customerNamed('أحمد السيد', '01011112222');
        $mona = $this->customerNamed('منى', '01233334444');

        $his = $this->orderFor($this->place, ['order_number' => 'CR7-5501-1', 'customer_name_snapshot' => 'أحمد السيد'], $ahmed);
        $hers = $this->orderFor($this->place, ['order_number' => 'CR7-7702-1', 'customer_name_snapshot' => 'منى'], $mona);

        // Hamza folded: «احمد» finds «أحمد».
        $this->assertSame([$his->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => 'احمد']]));
        // Every word must appear.
        $this->assertSame([$his->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => 'احمد السيد']]));
        $this->assertSame([], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => 'احمد منى']]));
        // A phone however it was typed: country code, spaces, Arabic-Indic digits.
        $this->assertSame([$his->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '+20 101 111']]));
        $this->assertSame([$hers->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '٠١٢٣٣٣']]));
        // A ref, part of one.
        $this->assertSame([$hers->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '7702']]));
        // The aliases.
        $this->assertSame([$his->id], $this->idsOf('/api/v1/admin/orders', ['search' => 'احمد']));
        $this->assertSame([$his->id], $this->idsOf('/api/v1/admin/orders', ['filter' => ['q' => 'احمد']]));
        // An id, exactly.
        $this->assertContains($hers->id, $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => (string) $hers->id]]));
        // `%` and `_` typed are literals.
        $this->assertSame([], $this->idsOf('/api/v1/admin/orders', ['filter' => ['search' => '%']]));
    }
}
