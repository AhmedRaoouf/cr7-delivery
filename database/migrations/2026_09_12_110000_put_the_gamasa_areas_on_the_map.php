<?php

use Database\Seeders\GamasaZoneGeometrySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Runs {@see GamasaZoneGeometrySeeder} on deploy.
 *
 * It is a migration rather than a line in `DatabaseSeeder` because the deployed
 * database is never re-seeded — `DEPLOY.md` runs `migrate --force` and nothing
 * else — and rows that already exist need the geometry written to them, not
 * re-created. This is the same shape as
 * `2026_09_20_090000_one_device_one_account_and_the_only_commission`.
 *
 * Safe to re-run: the seeder is a set of `update()` calls keyed on `name_en`.
 *
 * `down()` is deliberately empty. Reverting would mean writing `null` back over
 * fifteen researched pins to restore a state whose only behaviour was labelling
 * every address in town «جمصة — الشاطئ». There is nothing there worth
 * recovering, and a rollback that silently re-broke addressing would be worse
 * than one that does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new GamasaZoneGeometrySeeder)->run();
    }

    public function down(): void {}
};
