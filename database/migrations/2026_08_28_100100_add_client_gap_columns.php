<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the Flutter client's screens need and nothing exposed yet. Each one is a
 * field a built screen already draws; without them the app falls back to a default and
 * the screen quietly does less than it was designed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // CUS-44's three toggles, named for doc 11 §7's own categories. Kept on
            // the account, not on the device, so a reinstall does not silently
            // re-enable a channel the customer turned off.
            //
            // `notify_promos` defaults to **false**: an opt-out default on marketing
            // is a dark pattern, and the client already treats it that way. The two
            // operational channels default on — the customer must not be able to
            // silently lose the message that says their food is at the door.
            $table->boolean('notify_orders')->default(true)->after('theme');
            $table->boolean('notify_captain')->default(true)->after('notify_orders');
            $table->boolean('notify_promos')->default(false)->after('notify_captain');
        });

        Schema::table('drivers', function (Blueprint $table) {
            // DRV-07's "online for" timer. `is_online` says whether, never since when.
            $table->timestamp('online_since')->nullable()->after('is_online');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            // D-60 — derived from the menu (median base price), never hand-set, and
            // null while the restaurant has no published menu. CUS-12's price chips.
            $table->enum('price_band', ['budget', 'mid', 'premium'])->nullable()->after('menu_layout');
            $table->string('tagline_ar')->nullable()->after('description_en');
            $table->string('tagline_en')->nullable()->after('tagline_ar');
            // The printed menu sheets the restaurant handed us, shown whole in their
            // own section — «المنيو», pill 0 of the tab strip (D-58).
            $table->json('menu_sheets')->nullable()->after('cover');
            $table->string('brand_color', 9)->nullable()->after('menu_sheets');

            $table->index(['price_band', 'is_active']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // DRV-13 has to name the person it is delivering to. The row already
            // snapshots the restaurant's name; on a marketplace order there was no
            // equivalent for the buyer, so the captain saw an address and no name.
            $table->string('customer_name_snapshot')->nullable()->after('restaurant_name_snapshot');
        });

        // APP-03 submits an application, never an account (D-45). Captain accounts stay
        // Admin-created; this table is the queue an admin creates them from.
        Schema::create('captain_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();          // CAP-YYMMDD-NNNN
            $table->string('full_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('national_id')->nullable();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('city_text')->nullable();        // what the applicant typed
            $table->enum('vehicle_type', ['motorcycle', 'bicycle', 'car', 'scooter'])->default('motorcycle');
            $table->string('vehicle_plate')->nullable();
            $table->string('licence_file')->nullable();
            $table->string('national_id_file')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Set when an admin turns an approved application into a captain account.
            $table->foreignId('created_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captain_applications');

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('customer_name_snapshot'));

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['price_band', 'is_active']);
            $table->dropColumn(['price_band', 'tagline_ar', 'tagline_en', 'menu_sheets', 'brand_color']);
        });

        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn('online_since'));

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'notify_orders', 'notify_captain', 'notify_promos',
        ]));
    }
};
