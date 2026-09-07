<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes the operator asked for on 2026-09-08, and one column each.
 *
 * **1. An offer has no clock.** «ما يكونش 30 ثانية، هو يكون موجود لحد ما يرفض أو يقبل.»
 * `order_driver_attempts.expires_at` stays — it is history for every offer written
 * before today — but new rows leave it null, and null now means «still open». The
 * search widens instead of ending: `dispatch_last_offer_at` is when the last batch
 * went out, so `cr7:tick` can add more captains a minute later without re-offering to
 * the ones already holding it.
 *
 * **2. An admin assignment is an offer, not a fact.** «عايز في الإسناد يجيوا
 * notification ويقولوا كل حاجة ويقولوا الصفحة اللي فيها قبل ورفض.» `assigned_by` names
 * the operator who sent it, which is what lets `RejectOfferAction` tell them it was
 * declined rather than dropping the order back into the pool silently.
 *
 * **3. A blocked account is blocked everywhere.** `users.is_active` already existed and
 * was read at **login only** — a suspended account with a live token kept working until
 * the token happened to expire. The three columns here carry the reason and the actor so
 * the person is told why, and `EnsureAccountIsActive` reads them on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_driver_attempts', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('reason_code')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('dispatch_last_offer_at')->nullable()->after('dispatch_started_at');
            // Said once, not every minute — same reasoning as `errand_requests.admin_notified_at`.
            $table->timestamp('dispatch_admin_notified_at')->nullable()->after('dispatch_last_offer_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('is_active');
            $table->string('blocked_reason', 500)->nullable()->after('blocked_at');
            $table->foreignId('blocked_by')->nullable()->after('blocked_reason')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('errand_requests', function (Blueprint $table) {
            // Who has already been told. A captain who came online after the errand was
            // published never heard about it, because `publish()` ran once at creation
            // and nothing ran again — the same defect the food orders had, in a
            // different shape.
            $table->json('notified_driver_ids')->nullable()->after('admin_notified_at');
            $table->timestamp('last_published_at')->nullable()->after('notified_driver_ids');
        });
    }

    public function down(): void
    {
        Schema::table('order_driver_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['dispatch_last_offer_at', 'dispatch_admin_notified_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });

        Schema::table('errand_requests', function (Blueprint $table) {
            $table->dropColumn(['notified_driver_ids', 'last_published_at']);
        });
    }
};
