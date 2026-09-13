<?php

use App\Support\SettingsCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * doc 62 — 2026-09-13. Additive except for two enum widenings and one default.
 *
 * - `orders`: the ring's schedule (D-100), the two code switches snapshotted at placement
 *   (D-108), why a delivery fee was waived (D-105), the address as it was at placement
 *   (D-101), what the captain paid the place at pickup (D-103), and the statement that
 *   claimed the order for the place (D-104).
 * - `driver_transactions`: `place_advance`, and the settlement that closed each row.
 * - `settlements`: a captain's statement is an account statement — opening, what moved,
 *   what changed hands, closing.
 * - `otp_codes.purpose`: `address_phone` and `password_change` (D-106, D-112).
 * - `user_verified_phones`: the numbers an account has proven it holds (D-112).
 * - `settings`: every key the catalogue declares and the table lacks, at its default —
 *   `PATCH /admin/settings` used to skip a key with no row, in silence (D-113).
 * - `cart.max_restaurants` 3 → 2 where it still holds the seeded 3 (D-114).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('ring_attempts')->default(0)->after('renotify_count');
            $table->timestamp('ring_next_at')->nullable()->after('ring_attempts');
            $table->boolean('pickup_code_required')->default(true)->after('pickup_otp_attempts');
            $table->boolean('delivery_code_required')->default(true)->after('delivery_otp_resends');
            $table->string('free_delivery_reason', 20)->nullable()->after('delivery_fee_share');
            $table->json('delivery_address_snapshot')->nullable()->after('delivery_address_text');
            $table->decimal('place_paid_amount', 12, 2)->nullable()->after('platform_delivery_margin');
            $table->foreignId('restaurant_settlement_id')->nullable()->after('place_paid_amount')
                ->constrained('settlements')->nullOnDelete();

            $table->index(['status', 'ring_next_at']);
        });

        // A direct order has no delivery code (D-49); the snapshot says so for the ones
        // already in the table.
        DB::table('orders')->where('origin', 'restaurant_direct')->update(['delivery_code_required' => false]);

        DB::statement(
            "ALTER TABLE driver_transactions MODIFY COLUMN type
             ENUM('order_earning','bonus','deduction','cash_settlement','cash_collected','cash_handover','payout','place_advance') NOT NULL"
        );

        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->foreignId('settlement_id')->nullable()->after('order_id')->constrained('settlements')->nullOnDelete();
            $table->foreignId('errand_request_id')->nullable()->after('settlement_id')->constrained('errand_requests')->nullOnDelete();
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('opening_balance', 12, 2)->default(0)->after('net_amount');
            $table->decimal('cash_received', 12, 2)->default(0)->after('opening_balance');
            $table->decimal('paid_out', 12, 2)->default(0)->after('cash_received');
            $table->decimal('closing_balance', 12, 2)->default(0)->after('paid_out');
            $table->json('breakdown')->nullable()->after('closing_balance');
        });

        // `order` and `direct_order_food_passthrough` stay readable; the new lines say
        // which way the money went on each order (D-104).
        DB::statement("ALTER TABLE settlement_items MODIFY COLUMN type VARCHAR(40) NOT NULL DEFAULT 'order'");

        DB::statement(
            "ALTER TABLE otp_codes MODIFY COLUMN purpose
             ENUM('login','register','reset','verify','address_phone','password_change') NOT NULL"
        );

        Schema::create('user_verified_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['user_id', 'phone']);
        });

        foreach (SettingsCatalog::fields() as $key => $field) {
            // A key with no default is a number only the operator can know — a wallet, a
            // support line. Its row is the seeders' to write, with the operator's value.
            if ($field['default'] === null) {
                continue;
            }

            $exists = DB::table('settings')->where('key', $key)->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => json_encode($field['default']),
                    'group' => $field['group'],
                    'is_public' => (bool) ($field['public'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // D-114 — «مطعمين بس». Only over the seeded 3: a number the operator typed stays.
        // Read and compared in PHP: `value` is a JSON column, and MySQL compares a JSON
        // number with the string '3' as two different types.
        $limit = DB::table('settings')->where('key', 'cart.max_restaurants')->first();

        if ($limit !== null && (int) json_decode((string) $limit->value, true) === 3) {
            DB::table('settings')->where('id', $limit->id)->update(['value' => json_encode(2), 'updated_at' => now()]);
        }

        Cache::flush();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_verified_phones');

        DB::statement(
            "ALTER TABLE otp_codes MODIFY COLUMN purpose ENUM('login','register','reset','verify') NOT NULL"
        );

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'cash_received', 'paid_out', 'closing_balance', 'breakdown']);
        });

        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('errand_request_id');
            $table->dropConstrainedForeignId('settlement_id');
        });

        DB::statement(
            "ALTER TABLE driver_transactions MODIFY COLUMN type
             ENUM('order_earning','bonus','deduction','cash_settlement','cash_collected','cash_handover','payout') NOT NULL"
        );

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'ring_next_at']);
            $table->dropConstrainedForeignId('restaurant_settlement_id');
            $table->dropColumn([
                'ring_attempts', 'ring_next_at', 'pickup_code_required', 'delivery_code_required',
                'free_delivery_reason', 'delivery_address_snapshot', 'place_paid_amount',
            ]);
        });
    }
};
