<?php

use Database\Seeders\GamasaZoneGeometrySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-runs {@see GamasaZoneGeometrySeeder}, which now knows where «بنزينة توتال»
 * is.
 *
 * It was the sixteenth priced area and the one left unplaced on 2026-09-12,
 * because no map had it: an Overpass `amenity=fuel` sweep of the whole Gamasa
 * bbox returns two stations and neither is this one, and Nominatim, Photon and
 * Wikimapia are all empty here. Waze's local editors had it, satellite imagery
 * confirmed a real forecourt at their coordinates, and Nominatim's reverse
 * lookup confirmed the point is administratively in Gamasa — see the seeder for
 * the evidence and for what is still unverified about the brand.
 *
 * Until this runs, a customer at the station falls in no circle and has to find
 * the name in the list; `ZoneLocator::suggestFor()` cannot offer it, and the
 * home feed sorts it from the city centre ‪2.9‬ km away.
 *
 * Safe to re-run and safe to run before the seeder's other fifteen rows exist:
 * every write is an `update()` keyed on `name_en`.
 *
 * `down()` is empty for the same reason its predecessor's is — reverting means
 * writing `null` over a researched pin to restore a state that had no behaviour
 * worth recovering.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new GamasaZoneGeometrySeeder)->run();
    }

    public function down(): void {}
};
