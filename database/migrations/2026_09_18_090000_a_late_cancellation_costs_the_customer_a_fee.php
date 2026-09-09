<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator, 2026-09-09: «الغاء الطلب ينفع طالما المطعم مبدأش تحضير، ولو عايز
 * يلغي بعد كدا لازم يحول رسوم عشرين جنيه».
 *
 * doc 04 §1 gave the customer the cancel button up to `preparing` and handed
 * everything after that to Admin. It now stays theirs for one state longer —
 * `preparing` itself — at a price. `ready` is still the wall: past it the food
 * is packed and a captain is being found, and the customer is no longer the
 * only person whose plans change.
 *
 * Three columns and a knob:
 *
 * - `orders.cancellation_fee` — what was actually charged, snapshotted like
 *   every other money column on the row, so changing the setting tomorrow does
 *   not rewrite what a customer was told yesterday.
 * - `users.outstanding_fees` — where the fee lands when there is no refund to
 *   take it out of. A cash order has paid nothing, and that is the common case
 *   in Gamasa, so «deduct it from the refund» has to have an answer for the
 *   order that never had one. The next checkout adds it as its own line.
 * - `order_groups.outstanding_fees` — the amount that checkout collected, so the
 *   balance is cleared by the order that carried it and by nothing else. It
 *   sits beside `platform_fee` on the group for the same reason that one does:
 *   it is charged once per basket, not once per restaurant.
 * - `orders.late_cancellation_fee` — the knob, editable on `ADM-46` like the
 *   rest of D-63's money settings rather than frozen in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('cancellation_fee', 10, 2)->default(0)->after('cancellation_note');
        });

        Schema::table('order_groups', function (Blueprint $table) {
            $table->decimal('outstanding_fees', 10, 2)->default(0)->after('platform_fee');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('outstanding_fees', 10, 2)->default(0)->after('cash_only_until');
        });

        Settings::set('orders.late_cancellation_fee', 20.00, 'orders');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_fee');
        });

        Schema::table('order_groups', function (Blueprint $table) {
            $table->dropColumn('outstanding_fees');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('outstanding_fees');
        });

        Settings::set('orders.late_cancellation_fee', 0, 'orders');
    }
};
