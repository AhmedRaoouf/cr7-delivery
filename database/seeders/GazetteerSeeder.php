<?php

namespace Database\Seeders;

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
        Cache::forget('geo:gazetteer:ready');
    }

    /** @param array<string, mixed> $data */
    private function seedAreas(array $data): void
    {
        $rows = [];

        foreach ($data['areas'] ?? [] as $area) {
            $rows[] = [
                'name' => $area['name'],
                'name_en' => $area['name_en'] ?? null,
                'lat' => $area['lat'],
                'lng' => $area['lng'],
                'city' => null,
                'source' => $area['osm'] ?? null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('gazetteer_areas')->insert($chunk);
        }
    }

    /** @param array<string, mixed> $data */
    private function seedStreets(array $data): void
    {
        $rows = [];

        foreach ($data['streets'] ?? [] as $street) {
            $rows[] = [
                'name' => $street['name'],
                'kind' => $street['kind'] ?? null,
                'min_lat' => $street['min_lat'],
                'max_lat' => $street['max_lat'],
                'min_lng' => $street['min_lng'],
                'max_lng' => $street['max_lng'],
                'geom' => json_encode($street['geom'], JSON_UNESCAPED_UNICODE),
                'source' => $street['osm'] ?? null,
            ];
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

        $rows = [];

        foreach ($data['points'] ?? [] as $point) {
            [$lat, $lng, $houseNumber, $streetIx, $areaIx, $districtIx, $cityIx] = $point;

            $rows[] = [
                'lat' => $lat,
                'lng' => $lng,
                'house_number' => $houseNumber,
                'street' => $at($streets, (int) $streetIx),
                'area' => $at($areas, (int) $areaIx),
                'district' => $at($districts, (int) $districtIx),
                'city' => $at($cities, (int) $cityIx),
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('gazetteer_points')->insert($chunk);
        }
    }
}
