<?php

use App\Actions\Admin\DeletePlaceAction;
use App\Models\Restaurant;
use Illuminate\Database\Migrations\Migration;

/**
 * Doc 91 — brings every place deleted before the fix to the state a delete now leaves:
 * no basket lines, favourites, live banners or popups, dishes, pin or signed-in owner.
 * Data only; there is no schema change and nothing to roll back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Restaurant::onlyTrashed()->each(fn (Restaurant $place) => DeletePlaceAction::forget($place));
    }

    public function down(): void {}
};
