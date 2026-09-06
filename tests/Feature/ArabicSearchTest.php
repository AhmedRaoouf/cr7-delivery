<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Support\ArabicSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «ببعت ببحث كشر مش بيجيب كشري ليه بيجيب مفيش نتايج مع إن في كشري».
 *
 * `whereFullText` matched whole words only, ignored anything under three letters, and
 * folded nothing — so a prefix, a two-letter dish and a hamza typed the other way each
 * returned an empty list over a catalogue that had the row.
 */
class ArabicSearchTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->restaurant = $this->makeRestaurant('أبو طارق');
    }

    /** @return list<string> the product names the search answered with, in order. */
    private function search(string $needle): array
    {
        $response = $this->getJson('/api/v1/search?search='.urlencode($needle))->assertOk();

        return array_map(
            fn (array $row) => (string) $row['name'],
            $response->json('data.products') ?? [],
        );
    }

    public function test_a_prefix_finds_the_dish(): void
    {
        $this->makeProduct($this->restaurant, 'كشري', 40.0);

        $this->assertContains('كشري', $this->search('كشر'));
    }

    public function test_a_two_letter_needle_is_not_thrown_away(): void
    {
        // InnoDB's minimum token size is three; «رز» never reached the index at all.
        $this->makeProduct($this->restaurant, 'رز باللبن', 25.0);

        $this->assertContains('رز باللبن', $this->search('رز'));
    }

    public function test_the_hamza_and_the_ta_marbuta_are_folded(): void
    {
        $this->makeProduct($this->restaurant, 'شاورمة فراخ', 90.0);
        $this->makeProduct($this->restaurant, 'إفطار كامل', 55.0);

        // ة and ه are the same letter to a thumb; أ/إ/آ and ا are the same letter to
        // everyone who does not type the hamza.
        $this->assertContains('شاورمة فراخ', $this->search('شاورمه'));
        $this->assertContains('إفطار كامل', $this->search('افطار'));
    }

    public function test_a_prefix_covers_the_spellings_folding_cannot_reach(): void
    {
        // «شاورما» and «شاورمة» end in different letters, and no fold should pretend
        // otherwise. A prefix is what carries the customer over that: «شاورم» finds both.
        $this->makeProduct($this->restaurant, 'شاورما لحمة', 95.0);
        $this->makeProduct($this->restaurant, 'شاورمة فراخ', 90.0);

        $this->assertCount(2, $this->search('شاورم'));
    }

    public function test_every_word_typed_has_to_appear(): void
    {
        $this->makeProduct($this->restaurant, 'فراخ مشوية', 120.0);
        $this->makeProduct($this->restaurant, 'فراخ بانيه', 110.0);

        $this->assertSame(['فراخ مشوية'], $this->search('فراخ مشوي'));
    }

    public function test_the_exact_name_comes_first(): void
    {
        $this->makeProduct($this->restaurant, 'كشري بالعدس الأصفر', 55.0);
        $this->makeProduct($this->restaurant, 'كشري', 40.0);

        $this->assertSame('كشري', $this->search('كشري')[0]);
    }

    public function test_a_wildcard_typed_by_a_customer_is_a_literal(): void
    {
        $this->makeProduct($this->restaurant, 'كشري', 40.0);

        $this->assertSame([], $this->search('%'));
    }

    public function test_the_restaurant_is_found_by_a_prefix_too(): void
    {
        $response = $this->getJson('/api/v1/search?search='.urlencode('ابو'))->assertOk();

        $this->assertContains(
            'أبو طارق',
            array_map(fn (array $row) => (string) $row['name'], $response->json('data.restaurants') ?? []),
        );
    }

    public function test_normalize_strips_tashkeel_and_tatweel(): void
    {
        $this->assertSame('كشري', ArabicSearch::normalize('كُشـــرِيّ'));
        $this->assertSame([], ArabicSearch::tokens('   '));
    }
}
