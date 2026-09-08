<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The popup half of `banners.audience`.
 *
 * «العرض المنبثق» carries the same six destinations as a banner by design, and the
 * operator authors both from the same page — so «who sees it» has to mean the same
 * thing on both, or the free-first-delivery card could be shown as a popup to an
 * account that will be charged for it while the banner beside it knows better.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_popups', function (Blueprint $table) {
            $table->string('audience', 20)->default('all')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('home_popups', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
