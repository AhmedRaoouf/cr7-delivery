<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * D-63's «رسوم الخدمة» goes to **zero**, and stays a setting.
 *
 * Operator, 2026-09-08: «الـ5 جنيه بتاع المنصة هتتشال حاليًا، وأقدر برضه أتحكم
 * فيها من الأدمن — أقفلها وأفتحها».
 *
 * Nothing is deleted. `platform.order_fee` is still read by `CheckoutPricer` and
 * still editable on `ADM-46`; it is the *value* that changes, and the whole app
 * already treats zero as «no such line» — every one of the four screens that
 * prints it guards on `platformFee > 0`, so the row disappears rather than
 * announcing a charge of nothing.
 *
 * Orders already placed are untouched: `orders.platform_fee` is a snapshot taken
 * at checkout, so a receipt from last week still adds up to what was paid.
 *
 * Written as a migration rather than left to the seeder because the seeder does
 * not run against a live database — the number has to change on deploy, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Settings::set('platform.order_fee', 0, 'platform');
    }

    public function down(): void
    {
        Settings::set('platform.order_fee', 5.00, 'platform');
    }
};
