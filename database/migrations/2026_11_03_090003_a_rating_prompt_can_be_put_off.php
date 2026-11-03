<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 99 §6 — D-174: «لاحقًا» on the rating sheet is remembered by the server, so the
 * customer's other phone does not ask about the same basket again. The basket can still
 * be rated from its receipt; only the prompt is put away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_groups', function (Blueprint $table) {
            $table->timestamp('rating_prompt_dismissed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_groups', fn (Blueprint $table) => $table->dropColumn('rating_prompt_dismissed_at'));
    }
};
