<?php

use Database\Seeders\GazetteerSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local address book for the launch city, so a pin stops being named after
 * its delivery zone.
 *
 * The complaint that produced these tables: «مش دايمًا ألاقي جمصة الشاطئ — لا
 * اكتب المنطقة صح واسم الشارع اللي الموقع متحدد فيه». Two separate defects sat
 * behind it, and only one of them was ours:
 *
 * 1. Every priced area seeded by {@see \Database\Seeders\OperatorZonePricingSeeder}
 *    carries `radius_km = null` and the **city's** centre as its own, so
 *    `ZoneLocator::forPoint()` can never match one. Every pin fell through to
 *    the wide coverage circles, and the only one that actually covers Gamasa is
 *    «الشاطئ» — centre 31.4411, 31.5380, radius ‪6‬ km, which swallows the town.
 *    Hence «الشاطئ» on every address in a town where the beach is two rows of
 *    buildings.
 *
 * 2. Mapbox answers `neighborhood: null` for every point in Gamasa, so there was
 *    nothing to print but the zone. Measured on production 2026-09-12 across
 *    four pins: street yes, neighbourhood never. Nominatim, asked the same
 *    question about the same point, answers «منطقة آمون» — but it is second in
 *    {@see \App\Services\Maps\ChainGeocoder} and the chain stops at the first
 *    non-empty line, so it was never asked.
 *
 * Neither is fixed by choosing a different paid provider. OpenStreetMap already
 * holds, for this one town, **‪6 128‬ building address points**, ‪224‬ named street
 * polylines and ‪32‬ named areas — better coverage than either geocoder exposes
 * through its API. Seeded locally it answers in a millisecond, offline, free,
 * and it is the same data a captain's own map app will show them.
 *
 * The bbox is deliberately tight (31.40–31.48 N, 31.47–31.59 E). Outside it
 * {@see \App\Services\Maps\LocalGazetteerGeocoder} returns null and the chain
 * falls through to the providers, which is the right division of labour: we are
 * authoritative where we launch and nowhere else.
 *
 * @see https://osm.org/copyright — ODbL 1.0. Attribution ships in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Named residential areas — «منطقة آمون», «منطقة ‪6أ‬». This is the field
        // the customer was never shown.
        Schema::create('gazetteer_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('city')->nullable();
            $table->string('source')->nullable();
            $table->index(['lat', 'lng']);
        });

        // Named streets, kept as full polylines rather than centroids: the
        // centre of «الطريق الساحلي الدولي» is ‪14‬ km from most of it, so a
        // nearest-centroid answer would name the wrong road for half the town.
        Schema::create('gazetteer_streets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind')->nullable();
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->json('geom');
            $table->string('source')->nullable();
            // The bbox prefilter. Perpendicular distance is computed in PHP on
            // the handful of rows this returns.
            $table->index(['min_lat', 'max_lat']);
            $table->index(['min_lng', 'max_lng']);
        });

        // Building-level address points. `house_number` is the municipal number
        // on the door, not an interpolation — Mapbox's «‪18‬» for a point ‪100‬ m
        // from anything was interpolated, and a wrong door number sends a
        // captain to a neighbour.
        Schema::create('gazetteer_points', function (Blueprint $table) {
            $table->id();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('house_number')->nullable();
            $table->string('street')->nullable();
            $table->string('area')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->index(['lat', 'lng']);
        });

        // Seeded here rather than left to `DatabaseSeeder`, because a deployed
        // database is never re-seeded — `DEPLOY.md` runs `migrate --force` and
        // nothing else. Three empty tables would make
        // {@see \App\Services\Maps\LocalGazetteerGeocoder::isConfigured()}
        // answer false, the chain would fall straight back to the providers,
        // and the deploy would ship the schema for this fix without the fix.
        (new GazetteerSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('gazetteer_points');
        Schema::dropIfExists('gazetteer_streets');
        Schema::dropIfExists('gazetteer_areas');
    }
};
