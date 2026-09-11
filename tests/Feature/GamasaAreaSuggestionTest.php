<?php

namespace Tests\Feature;

use App\Support\ZoneLocator;
use Database\Seeders\GamasaZoneGeometrySeeder;
use Database\Seeders\OperatorZonePricingSeeder;
use Database\Seeders\ServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sixteen priced areas, each on the map where it actually is.
 *
 * D-70 seeded the operator's printed price list as **names**: `radius_km = null`
 * and the city's own centre copied into all sixteen rows, because nothing could
 * place «السجن القديم» or «عمارات مبارك». `ZoneLocator::forPoint()` can never
 * match a shapeless area, so every pin in the launch city fell through to the
 * wide legacy circles — and the only one of those that covers Gamasa is
 * «الشاطئ», radius ‪6‬ km. That is why every address in town read «جمصة —
 * الشاطئ», on a beach that is two rows of buildings.
 *
 * Each pin below was researched to two independent sources before it was
 * written — Wikimapia carried what OpenStreetMap does not for this town — and
 * the coordinates here are the *evidence*, not the seed: they are landmarks
 * (a mosque, a university, a hotel), and the test asserts that standing at one
 * offers the area it belongs to. A seeder that drifts fails this; a seeder
 * merely copied into the test would not.
 */
class GamasaAreaSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ServiceAreaSeeder::class,
            OperatorZonePricingSeeder::class,
            GamasaZoneGeometrySeeder::class,
        ]);
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: string}>
     */
    public static function landmarks(): array
    {
        return [
            'مسجد جمصة شرق الكبير' => [31.4348, 31.5584, 'جمصة شرق'],
            'قرية زيان' => [31.4035, 31.5015, 'زيان'],
            'مدينة ‪15‬ مايو، منطقة ‪6أ‬' => [31.4450, 31.5006, 'مايو'],
            'فندق القوات المسلحة' => [31.4372, 31.5400, 'القوات المسلحة'],
            'جامعة الدلتا للعلوم والتكنولوجيا' => [31.4423, 31.4938, 'دلتا'],
            'كورنيش المصيف' => [31.4453, 31.5344, 'داخلي جمصة'],
            'قرية الركابية' => [31.4145, 31.5685, 'الركابية'],
            'جمصة البلد' => [31.4345, 31.5505, 'جمصة البلد'],
            'مدينة الشيخ زايد بجمصة' => [31.4519, 31.4899, 'زايد'],
            'قرية البستان' => [31.4477, 31.5201, 'البستان'],
            'الإسكان الاجتماعي، جمصة ‪2‬' => [31.4336, 31.5274, 'جمصة 2 و3 و4'],
            'عمارات مبارك' => [31.4390, 31.5479, 'عمارات مبارك'],
            'منطقة سجون جمصة' => [31.4399, 31.5175, 'السجن القديم'],
            'منطقة إيزيس' => [31.4411, 31.5230, 'إيزيس'],
            'جامعة المنصورة الأهلية' => [31.4487, 31.4809, 'جامعة المنصورة الأهلية'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('landmarks')]
    public function test_standing_at_a_landmark_offers_the_area_it_is_in(
        float $lat,
        float $lng,
        string $area,
    ): void {
        $this->assertSame($area, ZoneLocator::suggestFor($lat, $lng)?->name_ar);
    }

    public function test_a_specific_area_beats_the_one_that_contains_it(): void
    {
        // «القوات المسلحة» is a ‪500‬ m circle wholly inside «داخلي جمصة», which
        // is ‪1.6‬ km. Ranked by flyer order the broad one wins — it is first on
        // the price list. Ranked by distance ÷ radius it *also* wins here, by a
        // rounding: the pin is ‪105‬ m into the small circle (0.21) and ‪315‬ m
        // into the big one (0.197). Radius first, distance only to break a tie.
        $this->assertSame('القوات المسلحة', ZoneLocator::suggestFor(31.4372, 31.5400)?->name_ar);
    }

    public function test_two_areas_the_same_size_are_settled_by_which_one_the_pin_is_near(): void
    {
        // «جمصة البلد» and «جمصة شرق» are both ‪800‬ m and both contain the east
        // mosque. The tie used to go to whichever the price list printed first,
        // which is the wrong one, and the customer would have been quoted it.
        $this->assertSame('جمصة شرق', ZoneLocator::suggestFor(31.4348, 31.5584)?->name_ar);
        $this->assertSame('جمصة البلد', ZoneLocator::suggestFor(31.4345, 31.5505)?->name_ar);
    }

    public function test_a_pin_with_no_area_near_it_is_offered_none(): void
    {
        // ‪4‬ km out to sea. At the old ‪12‬ km reach this was confidently offered
        // «البستان» — a real area, a real price, and nowhere near. «We do not
        // know, pick one» is the right answer and the flat list is there for it.
        $this->assertNull(ZoneLocator::suggestFor(31.4800, 31.5400));
    }

    public function test_the_area_nobody_could_place_is_left_unplaced(): void
    {
        // There is no Total station in Gamasa in any source — the nearest is Ras
        // El Bar, ‪27.8‬ km away — so «بنزينة توتال» has no centre and no radius
        // and is never suggested. It stays on the list for the customer who
        // knows what the name means locally. Guessing it is how the wrong pin
        // gets written, which is the whole defect this file exists for.
        $total = ZoneLocator::allSelectable()->firstWhere('name_en', 'Total Station');

        $this->assertNotNull($total, 'it must still be selectable — only unplaced');
        $this->assertNull($total->radius_km);
    }
}
