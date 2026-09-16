<?php

use App\Support\ArabicSearch;
use Database\Seeders\GazetteerSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-127 — the launch city's address book, made searchable.
 *
 * `2026_09_12_100000` seeded ‪6 128‬ doors, ‪224‬ streets and ‪32‬ areas and then read
 * them with `LIKE '%…%'` against the raw `name`. Three things followed from that,
 * and every one of them was invisible from the outside because the endpoint
 * answered ‪200‬ with an empty list:
 *
 * 1. **No Arabic folding.** OSM's own orthography is normalised into these rows,
 *    so they carry «منطقة الأندلس», «منطقة إيزيس», «شارع أبو عيش» — ‪19‬ street
 *    rows with a hamza, ‪44‬ with ة, ‪7‬ with ى. The ordinary Arabic keyboard types
 *    «الاندلس» and «ايزيس». {@see ArabicSearch} has folded both
 *    sides for the catalogue since the day «كشر» could not find «كشري»; the
 *    geocoder was simply never wired into it.
 * 2. **Nothing to index.** Neither `name` column carried an index at all.
 * 3. **The doors were never searched.** `gazetteer_points` has no name column —
 *    only `house_number`, `street`, `area` — so the entire searchable vocabulary
 *    of the launch city was ‪81‬ distinct street names plus ‪32‬ areas: ‪113‬ strings
 *    for a town with ‪6 128‬ mapped buildings in it.
 *
 * The fix for (1) cannot be «fold in SQL»: `ArabicSearch::columnSql()` nests one
 * `REPLACE` per folded or stripped character around the column — over thirty
 * of them — which no index can serve and which
 * every one of those ‪6 128‬ rows would pay on every keystroke in the address
 * picker. So the folded form is **stored** — written once by the seeder, read
 * straight — and indexed.
 *
 * The fix for (3) is a label built out of the three columns the door already
 * has: «‪145‬ شارع إسماعيل فريد، منطقة الكرنك». The door is then findable by its
 * number, by its street and by its area, which is how people in the planned
 * districts actually give their address.
 *
 * Re-seeding rather than back-filling in place: {@see GazetteerSeeder} is
 * idempotent by construction — it truncates and reloads from
 * `database/data/gamasa-gazetteer.json` — nothing references these rows by id,
 * and a reload is the documented way to update coverage. It is also the only
 * way the columns get filled on a deployed database, which never re-runs a
 * seeder: `DEPLOY.md` runs `migrate --force` and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gazetteer_areas', function (Blueprint $table) {
            $table->string('name_normalized')->nullable()->after('name');
            $table->index('name_normalized');
        });

        Schema::table('gazetteer_streets', function (Blueprint $table) {
            $table->string('name_normalized')->nullable()->after('name');
            $table->index('name_normalized');
        });

        Schema::table('gazetteer_points', function (Blueprint $table) {
            // `label` is what the row is found *by*; the display line is still
            // composed from `house_number` / `street` at read time, because the
            // number needs its LTR isolate and a stored one would be searched
            // with invisible characters in the middle of it.
            $table->string('label')->nullable()->after('city');
            $table->string('label_normalized')->nullable()->after('label');
            $table->index('label_normalized');
        });

        (new GazetteerSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('gazetteer_areas', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropColumn('name_normalized');
        });

        Schema::table('gazetteer_streets', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropColumn('name_normalized');
        });

        Schema::table('gazetteer_points', function (Blueprint $table) {
            $table->dropIndex(['label_normalized']);
            $table->dropColumn(['label', 'label_normalized']);
        });
    }
};
