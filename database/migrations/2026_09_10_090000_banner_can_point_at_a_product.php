<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A banner can point at one dish.
 *
 * Operator, 2026-09-07: «العروض دي ربطها بالمطاعم بحيث لما أجي أعمل طلب الآن يوديني على
 * المنتج اللي على العرض». `banners.link_type` was a MySQL `enum` of five values, so the
 * new one is a schema change rather than a code change — an unknown value on an `enum`
 * column is not rejected, it is **truncated to empty**, which is a banner that silently
 * stops linking anywhere.
 *
 * Raw SQL rather than `$table->enum(...)->change()`: doctrine/dbal is not installed, and
 * `change()` on an enum without it drops the column's values.
 */
return new class extends Migration
{
    private const VALUES = "'restaurant','product','category','promo','url','none'";

    private const PREVIOUS = "'restaurant','category','promo','url','none'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `banners` MODIFY `link_type` ENUM('.self::VALUES.") NOT NULL DEFAULT 'none'",
        );
    }

    public function down(): void
    {
        // Anything already pointing at a product would truncate to empty on the way
        // back, so it is put down as «no destination» first — a slide that goes
        // nowhere, which is what it would have been before this column knew the word.
        DB::table('banners')->where('link_type', 'product')->update([
            'link_type' => 'none',
            'link_value' => null,
        ]);

        DB::statement(
            'ALTER TABLE `banners` MODIFY `link_type` ENUM('.self::PREVIOUS.") NOT NULL DEFAULT 'none'",
        );
    }
};
