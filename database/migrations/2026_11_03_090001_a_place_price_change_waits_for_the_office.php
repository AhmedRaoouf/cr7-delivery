<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * doc 99 §1 — D-169: a place's price change waits for the office.
 *
 * The request table is the **only** place a requested price lives. Nothing on
 * `products` or `product_options` holds a pending value, so every customer read keeps
 * reading the live columns exactly as it did before (`LinePrice`, the cart, checkout).
 *
 * Rows are history and are never pruned: a withdrawn or superseded request stays, so the
 * place and the office can both see what was asked and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_requests', function (Blueprint $table) {
            $table->id();
            // Products and places are soft-deleted, so these rows outlive a deleted dish.
            // No cascade on `product_id`: MySQL refuses one on the base column of the stored
            // generated column below, and no hard delete of a product exists in the API.
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'superseded', 'withdrawn'])->default('pending');
            // The live values when the request was filed.
            $table->decimal('current_price', 12, 2);
            $table->decimal('current_discount_price', 12, 2)->nullable();
            // Null = that field is not part of the request.
            $table->decimal('requested_price', 12, 2)->nullable();
            $table->decimal('requested_discount_price', 12, 2)->nullable();
            $table->boolean('clears_discount')->default(false);
            $table->json('option_changes')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            // The request that replaced this one, when it was superseded.
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->timestamps();

            // «At most one pending row per product», held by the database and not only by
            // the code: a generated column that is the product id while pending and null
            // otherwise, unique (MySQL allows any number of nulls).
            $table->unsignedBigInteger('pending_product_id')
                ->nullable()
                ->storedAs("if(`status` = 'pending', `product_id`, null)")
                ->unique();

            $table->index(['product_id', 'status']);
            $table->index(['restaurant_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // The permission. `PermissionsSeeder` is `firstOrCreate`, but a deploy runs
        // `migrate`, not the seeders — so the row is created here and handed to the role
        // that holds every permission.
        $now = now();
        $id = DB::table('permissions')->where('name', 'products.approve_prices')->where('guard_name', 'web')->value('id');

        if ($id === null) {
            $id = DB::table('permissions')->insertGetId([
                'name' => 'products.approve_prices', 'guard_name' => 'web', 'group' => 'products',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($roleId !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_requests');

        $id = DB::table('permissions')->where('name', 'products.approve_prices')->value('id');

        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->whereKey($id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
