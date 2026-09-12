<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\ServiceZone;
use Illuminate\Database\Seeder;

/**
 * D-70 — the operator's printed price list, area by area.
 *
 * Source: the CR7 Delivery flyer the operator photographed on 2026-09-05. Two columns —
 * Gamasa and its villages on the left, المنصورة الجديدة on the right — 31 areas, one
 * delivery fee each. **These numbers are the operator's and are not derived from
 * distance.** Anyone tempted to recompute them from coordinates should read the fees for
 * الصناعية الأولى (30) and زاهية 4 (150) first.
 *
 * ## Arabic
 *
 * The flyer is handwritten in dialect spelling; D-44 is MSA everywhere, so «عماير» →
 * «عمارات», «الركابيه» → «الركابية», «الصناعيه» → «الصناعية», «جنه» → «جنة», «زاهيه» →
 * «زاهية», «كارته» → «كارتة». Proper nouns — إيزيس, زيان, دلتا, الناقر — are untouched.
 *
 * ## Sort order
 *
 * Cheapest first, which is the order a price list is read in. The picker shows the fee
 * beside every row, so a customer scanning for their own area sees the price without
 * opening anything.
 *
 * ## What this does NOT do
 *
 * It draws no shapes. `radius_km` stays null on all 31, so none of them ever matches a
 * pin — see the migration's note. Coverage is `cities.radius_km`, set here, and the two
 * pre-existing wide Gamasa circles plus New Mansoura's keep resolving pins exactly as
 * before; they are only taken off the price list via `is_selectable = false`.
 *
 * Sheikh Zayed and 15 May are not on the flyer and are left with the single flat-fee
 * zone `ServiceAreaSeeder` gave them.
 *
 * Idempotent: keyed on `city_id` + `name_en`, safe on every deploy.
 */
class OperatorZonePricingSeeder extends Seeder
{
    /** [name_ar, name_en, fee] — flyer order preserved inside each city, cheapest first. */
    private const GAMASA = [
        ['داخلي جمصة', 'Gamasa Inner', 20],
        ['إيزيس', 'Isis', 20],
        ['القوات المسلحة', 'Armed Forces', 25],
        ['البستان', 'Al Bustan', 25],
        ['جمصة 2 و3 و4', 'Gamasa 2, 3 and 4', 25],
        ['جمصة البلد', 'Gamasa Al Balad', 25],
        ['جمصة شرق', 'East Gamasa', 25],
        ['عمارات مبارك', 'Mubarak Buildings', 25],
        ['السجن القديم', 'Old Prison', 25],
        ['مايو', 'Mayo', 35],
        ['دلتا', 'Delta', 35],
        ['زايد', 'Zayed', 50],
        ['بنزينة توتال', 'Total Station', 50],
        ['الركابية', 'Al Rakabiya', 60],
        ['جامعة المنصورة الأهلية', 'Mansoura National University', 60],
        ['زيان', 'Zayan', 70],
    ];

    private const NEW_MANSOURA = [
        ['الصناعية الأولى', 'First Industrial', 30],
        ['الصناعية الثانية', 'Second Industrial', 30],
        ['الناقر', 'Al Naqer', 35],
        ['كارتة الدولي', 'Karta Al Dawly', 35],
        ['الصناعية الثالثة', 'Third Industrial', 40],
        ['الصناعية الرابعة', 'Fourth Industrial', 55],
        ['سكن 1 والأهلية', 'Housing 1 and National University', 70],
        ['سكن 2', 'Housing 2', 80],
        ['جنة 1', 'Ganna 1', 80],
        ['كورنيش البحر', 'Sea Corniche', 80],
        ['سكن 3', 'Housing 3', 90],
        ['جنة 2', 'Ganna 2', 90],
        ['جنة 3', 'Ganna 3', 100],
        ['جنة 4', 'Ganna 4', 100],
        ['زاهية 4', 'Zahia 4', 150],
    ];

    /**
     * Coverage circles, km. Generous on purpose: this decides «نوصّل هنا؟», not the price.
     *
     * Generous is not unbounded. At ‪15‬ km Gamasa's circle reached دمياط الجديدة — ‪12.7‬ km
     * east of the town and a city this platform does not serve — so `POST /geo/resolve`
     * called a pin there serviceable while the app, which resolves against the zone
     * circles alone, called the same pin «خارج نطاق التوصيل». One point, two answers.
     * ‪10‬ km covers the western expansions, the universities and the villages and stops
     * short of Damietta; see {@see ServiceAreaSeeder} for where the two centres moved to.
     */
    private const CITY_RADIUS_KM = [
        'Gamasa' => 10,
        'New Mansoura' => 9,
        'Sheikh Zayed City' => 12,
        '15th of May City' => 10,
    ];

    public function run(): void
    {
        foreach (self::CITY_RADIUS_KM as $nameEn => $radiusKm) {
            City::query()->where('name_en', $nameEn)->update(['radius_km' => $radiusKm]);
        }

        $this->pricedZones('Gamasa', self::GAMASA);
        $this->pricedZones('New Mansoura', self::NEW_MANSOURA);
    }

    private function pricedZones(string $cityNameEn, array $rows): void
    {
        $city = City::query()->where('name_en', $cityNameEn)->first();

        if (! $city) {
            return;
        }

        $names = array_column($rows, 1);

        // Every zone the city already had — the wide circles restaurants, addresses and
        // `service_zone_driver` point at. Left active and left resolving; taken off the
        // price list only. Deleting them would cascade into live rows.
        ServiceZone::query()
            ->where('city_id', $city->id)
            ->whereNotIn('name_en', $names)
            ->update(['is_selectable' => false]);

        foreach ($rows as $index => [$nameAr, $nameEn, $fee]) {
            ServiceZone::query()->updateOrCreate(
                ['city_id' => $city->id, 'name_en' => $nameEn],
                [
                    'name_ar' => $nameAr,
                    // The city's own point. Not a claim about where the area is — these
                    // rows are picked, never matched — and `radius_km` stays null so no
                    // pin can accidentally land in one.
                    'center_lat' => $city->center_lat,
                    'center_lng' => $city->center_lng,
                    'radius_km' => null,
                    'delivery_fee' => $fee,
                    'min_order_value' => $city->default_min_order,
                    'avg_delivery_minutes' => $city->name_en === 'Gamasa' ? 30 : 40,
                    'is_active' => true,
                    'is_selectable' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
