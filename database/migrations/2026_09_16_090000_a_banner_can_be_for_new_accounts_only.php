<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator, 2026-09-08: «خلي اول اورد للحساب توصيل مجاني واستخدمها بقا في العروض اللي
 * بتظهر اول ما تدخل الرئيسيه وكدا».
 *
 * Two halves, and they belong in one migration because neither is any use alone:
 *
 * - `delivery.first_order_free` — the offer itself, applied in `CheckoutPricer` and
 *   switchable from Admin like every other knob.
 * - `banners.audience` — so the slide that announces it is shown only to the accounts
 *   it is true for. A slide promising free delivery to somebody who will be charged
 *   for it is worse than no slide.
 *
 * It also makes the English half of a banner optional. `title_en` and `image_en` were
 * `NOT NULL` with no default, so a banner could only be created by producing two pieces
 * of artwork and two headlines — and the dashboard, which sent neither, could not create
 * one at all. The app already falls back to the Arabic side when the English is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('audience', 20)->default('all')->after('link_value');
            $table->string('title_en')->nullable()->change();
            $table->string('image_en')->nullable()->change();
        });

        Settings::set('delivery.first_order_free', 1, 'delivery');
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('audience');
            $table->string('title_en')->nullable(false)->change();
            $table->string('image_en')->nullable(false)->change();
        });

        Settings::set('delivery.first_order_free', 0, 'delivery');
    }
};
