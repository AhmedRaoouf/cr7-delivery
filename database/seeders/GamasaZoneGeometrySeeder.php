<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\ServiceZone;
use Illuminate\Database\Seeder;

/**
 * Puts the operator's priced Gamasa areas on the map.
 *
 * D-70 seeded the printed price list as sixteen **names** — `radius_km = null`
 * and the city's own centre copied into every row — because nothing could place
 * «السجن القديم» or «عمارات مبارك». The consequences ran much further than a
 * missing feature:
 *
 * - `ZoneLocator::forPoint()` can never match a shapeless area, so every pin in
 *   the launch city fell through to the wide legacy coverage circles. Only one
 *   of those actually covers Gamasa — «الشاطئ», radius ‪6‬ km — so **every
 *   address in town was labelled «جمصة — الشاطئ»**, which is what the operator
 *   reported on 2026-09-12. The beach is two rows of buildings facing the sea.
 * - Every area shared one centre, so the home feed's `lat`/`lng` were identical
 *   for all sixteen and «الأقرب» meant nothing.
 *
 * So each one was researched against sources rather than estimated from a map.
 * **Wikimapia** carried what OpenStreetMap does not: ‪1 177‬ features around
 * Gamasa, including the villages, the prison complex and the resort polygon. Two
 * independent sources were required before a pin was written, and the one area
 * that could not meet that bar is left alone rather than guessed at.
 *
 * The radii are deliberately tight. They exist so a pin can be **suggested** an
 * area, and {@see ZoneLocator::suggestFor()} prefers the smallest containing one
 * — so an over-wide circle does not merely blur the answer, it swallows its
 * neighbours. Where a pin falls in no circle the customer picks from the list,
 * which is the honest outcome and the one D-70 was built around.
 *
 * New Mansoura's fifteen areas are untouched: nobody has researched them, and a
 * guess there would reintroduce exactly the defect this seeder exists to fix.
 */
class GamasaZoneGeometrySeeder extends Seeder
{
    /**
     * name_en => [lat, lng, radius_km, evidence]
     *
     * `name_en` is the key because it is what {@see OperatorZonePricingSeeder}
     * writes and it does not carry the Arabic orthography questions the display
     * names do.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: string}>
     */
    private const GEOMETRY = [
        'Gamasa Inner' => [31.4375, 31.5433, 1.6,
            'Wikimapia polygon «مصيف جمصه», N 31.4517 / S 31.4232 / E 31.5666 / W 31.5199.'],
        'Isis' => [31.4411, 31.5230, 0.5,
            'OSM place=neighbourhood «منطقة ايزيس» 31.441097, 31.522953.'],
        'Armed Forces' => [31.4371, 31.5411, 0.5,
            'Four Wikimapia features within 170 m: «فندق القوات المسلحه», «فندق 6 اكتوبر», «نادى 6 أكتوبر», «عمارات مصنع 63 الحربى».'],
        'Al Bustan' => [31.4477, 31.5201, 0.6,
            'Wikimapia «قرية بستان جمصة»; corroborated by listings placing it opposite «السيرك القومى» (31.44450, 31.52204), 360 m away.'],
        'Gamasa 2, 3 and 4' => [31.4335, 31.5282, 0.6,
            'Wikimapia «Gamasa 2/3/4» agreeing with OSM «الاسكان الاجتماعى بجمصه 2» to within 20 m; centre of the three social-housing blocks.'],
        'Gamasa Al Balad' => [31.4345, 31.5505, 0.8,
            'Built core: Wikimapia «جمصة البلد» ×2, «مسجد جمصة غرب الكبير», the two «جمصة غرب» schools. NOT 31.428/31.553 — that is the canal bridge in farmland, 800 m south-east.'],
        'East Gamasa' => [31.4352, 31.5569, 0.8,
            'Three facilities carrying the name: «مسجد جمصة شرق الكبير», «مدرسة جمصة شرق الابتدائية», «مدرسة جمصة شرق الاعدادية». East of the canal line at lng 31.553.'],
        // Deliberately the tightest circle on this list, and tighter than its
        // own uncertainty. At ‪600‬ m it reached the centre of «جمصة البلد» — a
        // CONFIRMED area — and, being the smaller of the two, won every pin
        // there. An area we are less sure of must claim *less* ground, not
        // more: at ‪350‬ m only points close to the best estimate are offered it,
        // and everything else falls to the list, where the customer knows.
        'Mubarak Buildings' => [31.4390, 31.5479, 0.35,
            'LIKELY, ±500 m. Listings place «اسكان مبارك» on the main street near the church (Wikimapia ×3 at 31.4392, 31.5479); another puts it by the old town entrance, so the cluster runs along شارع جمال عبد الناصر.'],
        'Old Prison' => [31.4399, 31.5175, 0.7,
            'Wikimapia «سجن المنصورة الجديد 2015» + «الأمن المركزى»; ANHRI describes one 42 000 m² «منطقة سجون جمصة» beside the town entrance on the coastal road. The word «القديم» is not separately documented — this pins the complex, not a building.'],
        'Mayo' => [31.4471, 31.5066, 1.2,
            'Wikimapia «مدينة 15 مايو» 31.44778, 31.50851 with OSM areas 1أ…6ج spanning 31.4403-31.4548 / 31.4998-31.5139.'],
        'Delta' => [31.4423, 31.4938, 0.6,
            'OSM amenity=university «جامعة الدلتا للعلوم والتكنولوجيا» 31.442289, 31.4938156; Wikimapia agrees to 5 m.'],
        'Zayed' => [31.4519, 31.4899, 1.2,
            'OSM place=neighbourhood «مدينة الشيخ زايد بجمصة» 31.4518867, 31.4899118.'],
        'Al Rakabiya' => [31.4145, 31.5685, 1.2,
            'Five Wikimapia features: «قرية الركابية», its health unit, two schools and the youth centre, spanning 31.4132-31.4158.'],
        'Mansoura National University' => [31.4487, 31.4809, 0.8,
            'OSM 31.4486944, 31.480921. NOT «New Mansoura University» at 31.4610, 31.4571 — a different campus 3.5 km north-west.'],
        'Zayan' => [31.4035, 31.5015, 1.2,
            'Four Wikimapia features: «قرية زيان», «مدينة زيان», the primary school and the cemetery. Press places it in مركز بلقاس on the Gamasa-Mansoura road, 517 m from the OSM trunk route.'],

        // Found 2026-09-12, and it was in none of the sources this seeder
        // originally searched because **it is not in OpenStreetMap at all** —
        // an Overpass `amenity=fuel` sweep of the whole bbox returns exactly
        // two nodes, «Co-op» and «Misr», and neither is this. Nominatim,
        // Photon and Wikimapia have nothing here either.
        //
        // Waze does: its venue search answers «محطة وقود توتال» at
        // 31.43624765, 31.56695765 — the only named station its local editors
        // know anywhere near Gamasa. Satellite imagery at that point shows a
        // fuel station and not an approximation of one: a walled paved
        // forecourt, a long rectangular pump canopy, a service building, two
        // driveways onto the trunk road, and the station mosque ‪80‬ m
        // north-east. Nominatim reverse on the point answers «الطريق الساحلى
        // الدولى, جمصة, الدقهلية», so it is administratively in Gamasa rather
        // than in Damietta — which matters, because it sits ‪2.9‬ km **east**
        // of the town, out among the orchards.
        //
        // What is still unverified is the brand. TotalEnergies Marketing Egypt
        // runs ~‪245‬ stations, and its nearest confirmed one is Ras El Bar,
        // ‪25.8‬ km away (OSM `brand=Total`); no official directory places one
        // in Gamasa. So «توتال» here is what the town calls it, which is the
        // only thing a delivery area needs to be right about — the operator
        // typed that name into the price list, and this is the place the name
        // points at.
        //
        // The earlier note in this file listed a third station at 31.42794,
        // 31.54706. **There is nothing there** — no OSM element by two queries,
        // and the imagery shows the town gate, a toll building and chalets.
        'Total Station' => [31.436248, 31.566958, 0.6,
            'Waze venue «محطة وقود توتال» 31.43624765/31.56695765; ESRI World Imagery shows the forecourt and pump canopy at the same point; Nominatim reverse puts it on الطريق الساحلى الدولى in جمصة. Radius ‪0.6‬ km — the nearest area edge, «داخلي جمصة», is ‪0.65‬ km further out.'],
    ];

    public function run(): void
    {
        $gamasa = City::query()->where('name_en', 'Gamasa')->first();

        if ($gamasa === null) {
            return;
        }

        foreach (self::GEOMETRY as $nameEn => [$lat, $lng, $radius, $_evidence]) {
            ServiceZone::query()
                ->where('city_id', $gamasa->id)
                ->where('name_en', $nameEn)
                ->update([
                    'center_lat' => $lat,
                    'center_lng' => $lng,
                    'radius_km' => $radius,
                ]);
        }
    }
}
