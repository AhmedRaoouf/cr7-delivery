<?php

namespace Database\Seeders;

use App\Services\Maps\LocalGazetteerGeocoder;
use App\Support\ArabicSearch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Loads `database/data/gamasa-gazetteer.json` into the three gazetteer tables.
 *
 * The file is OpenStreetMap extract, fetched 2026-09-12 over the bbox
 * 31.40–31.48 N, 31.47–31.59 E: ‪32‬ named areas, ‪224‬ named street polylines
 * (‪3 826‬ vertices) and ‪6 128‬ building address points. Its street, area,
 * district and city strings are **interned** — the points hold indices into
 * four lookup tables — because ‪6 128‬ rows carry only ‪30‬ distinct area names
 * and ‪4‬ districts, and spelling each one out per row made the file ‪1‬ MB
 * instead of ‪350‬ KB.
 *
 * Arabic orthography is normalised in the file, not here: OSM writes «مدينه
 * جمصه» and «منطقة ايزيس», and those strings go straight onto the customer's
 * screen, so they are «جمصة» and «منطقة إيزيس» by the time they are committed.
 *
 * Idempotent — it truncates and reloads, so re-running it after a fresh extract
 * is the supported way to update coverage. Nothing references these rows by id.
 */
class GazetteerSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('gazetteer_points')) {
            return;
        }

        $path = database_path('data/gamasa-gazetteer.json');

        if (! is_file($path)) {
            throw new RuntimeException("Gazetteer data file is missing: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        DB::table('gazetteer_points')->truncate();
        DB::table('gazetteer_streets')->truncate();
        DB::table('gazetteer_areas')->truncate();

        $this->seedAreas($data);
        $this->seedStreets($data);
        $this->seedPoints($data);

        // {@see \App\Services\Maps\LocalGazetteerGeocoder::isConfigured()} caches
        // «are there rows» for an hour. Without this, a seed on a warm server is
        // invisible to the geocoder until the hour is out.
        Cache::forget(LocalGazetteerGeocoder::READY_KEY);
        // Every cached forward-search answer is keyed on this stamp, so dropping
        // it is how a re-seed invalidates them. There is no pattern-delete that
        // works on every cache store, and a search answered out of the old rows
        // would outlive the reload by a week.
        Cache::forget(LocalGazetteerGeocoder::STAMP_KEY);
    }

    /**
     * The folded columns only exist after `2026_09_25_090000`.
     *
     * `2026_09_12_100000` calls this seeder from inside its own `up()`, so on a
     * fresh install it runs against the table as that migration created it —
     * three columns short. Writing them unconditionally would make every new
     * database fail to migrate, and the later migration re-runs the seeder once
     * the columns exist.
     */
    private function searchable(string $table): bool
    {
        return Schema::hasColumn($table, $table === 'gazetteer_points' ? 'label_normalized' : 'name_normalized');
    }

    /**
     * What a door is found by: «‪145‬ شارع إسماعيل فريد، منطقة الكرنك».
     *
     * All three parts, because all three are how somebody gives this address.
     * In the planned districts — «منطقة ‪6أ‬», ‪4 182‬ buildings, almost no named
     * internal roads — the building number *is* the address, and the only other
     * thing the door carries is its area.
     *
     * No LTR isolates here. They are invisible characters in the middle of a
     * string that is about to be matched character by character, and the display
     * line is composed separately at read time where they belong.
     */
    private static function label(?string $houseNumber, ?string $street, ?string $area): ?string
    {
        $line = trim(implode(' ', array_filter([$houseNumber, $street])));
        $parts = array_values(array_filter([$line === '' ? null : $line, $area]));

        return $parts === [] ? null : implode('، ', $parts);
    }

    /** @param array<string, mixed> $data */
    private function seedAreas(array $data): void
    {
        $searchable = $this->searchable('gazetteer_areas');
        $rows = [];

        foreach ($data['areas'] ?? [] as $area) {
            $row = [
                'name' => $area['name'],
                'name_normalized' => ArabicSearch::normalize((string) $area['name']),
                'name_en' => $area['name_en'] ?? null,
                'lat' => $area['lat'],
                'lng' => $area['lng'],
                'city' => null,
                'source' => $area['osm'] ?? null,
            ];

            if (! $searchable) {
                unset($row['name_normalized']);
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('gazetteer_areas')->insert($chunk);
        }
    }

    /** @param array<string, mixed> $data */
    private function seedStreets(array $data): void
    {
        $searchable = $this->searchable('gazetteer_streets');
        $rows = [];

        foreach ($data['streets'] ?? [] as $street) {
            $row = [
                'name' => $street['name'],
                'name_normalized' => ArabicSearch::normalize((string) $street['name']),
                'kind' => $street['kind'] ?? null,
                'min_lat' => $street['min_lat'],
                'max_lat' => $street['max_lat'],
                'min_lng' => $street['min_lng'],
                'max_lng' => $street['max_lng'],
                'geom' => json_encode($street['geom'], JSON_UNESCAPED_UNICODE),
                'source' => $street['osm'] ?? null,
            ];

            if (! $searchable) {
                unset($row['name_normalized']);
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('gazetteer_streets')->insert($chunk);
        }
    }

    /** @param array<string, mixed> $data */
    private function seedPoints(array $data): void
    {
        $streets = $data['streets_lookup'] ?? [];
        $areas = $data['areas_lookup'] ?? [];
        $districts = $data['districts_lookup'] ?? [];
        $cities = $data['cities_lookup'] ?? [];

        // `-1` is «the tag was absent on that OSM node», which is a real and
        // common answer: ‪1 269‬ of the ‪6 128‬ doors carry a street and no area,
        // and the rest carry an area and no street.
        $at = static fn (array $table, int $index): ?string => $index < 0 ? null : ($table[$index] ?? null);

        $searchable = $this->searchable('gazetteer_points');
        $rows = [];

        foreach ($data['points'] ?? [] as $point) {
            [$lat, $lng, $houseNumber, $streetIx, $areaIx, $districtIx, $cityIx] = $point;

            $street = $at($streets, (int) $streetIx);
            $area = $at($areas, (int) $areaIx);
            $label = self::label(
                $houseNumber === null ? null : (string) $houseNumber,
                $street,
                $area,
            );

            $row = [
                'lat' => $lat,
                'lng' => $lng,
                'house_number' => $houseNumber,
                'street' => $street,
                'area' => $area,
                'district' => $at($districts, (int) $districtIx),
                'city' => $at($cities, (int) $cityIx),
                'label' => $label,
                'label_normalized' => $label === null ? null : ArabicSearch::normalize($label),
            ];

            if (! $searchable) {
                unset($row['label'], $row['label_normalized']);
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('gazetteer_points')->insert($chunk);
        }
    }
}
