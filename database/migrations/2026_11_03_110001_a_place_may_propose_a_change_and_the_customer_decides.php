<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * doc 99 §4, D-172 — «عند وصول أوردر للمكان يتمكن المكان من مراجعة الطلب وتعديله في حالة
     * وجود أصناف غير متوفرة مع إرسال التعديلات للعميل لمراجعتها ولا يتم استكمال الطلب إلا بعد
     * موافقة العميل».
     *
     * Additive only: one new table, one switch per place (off), two nullable columns on
     * `orders`, two permissions handed to `super_admin`.
     *
     * - `order_amendments` — what the place (or the office) proposed, the money before and
     *   after, and how the customer answered. One `pending` row per order at most; the
     *   rule is enforced under a row lock in the action, not by an index (MySQL has no
     *   partial unique index).
     * - `orders.amendment_status` — the latest amendment's status, denormalised so the
     *   board can put a `pending` order with a pending amendment in «بانتظار العميل»
     *   with one indexed `where` and no join.
     * - `orders.answer_window_started_at` — when the place's answer window (the
     *   `restaurant_timeout` clock) restarted after an amendment closed. Null means the
     *   window runs from `created_at`, which is every order placed before this batch.
     */
    public function up(): void
    {
        Schema::create('order_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proposer_type', 16);
            $table->string('status', 16)->default('pending');
            $table->json('lines');
            $table->json('additions');
            $table->json('money_before');
            $table->json('money_after');
            $table->decimal('delta', 10, 2)->default(0);
            $table->unsignedSmallInteger('prep_minutes');
            $table->string('restaurant_order_ref', 32)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 500)->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['order_group_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('amendments_enabled')->default(false)->after('direct_orders_enabled');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('amendment_status', 16)->nullable()->after('status');
            $table->timestamp('answer_window_started_at')->nullable()->after('placed_at');
        });

        $now = now();
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        foreach (['orders.amend' => 'orders', 'restaurants.toggle_amendments' => 'restaurants'] as $name => $group) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

            if ($id === null) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => 'web', 'group' => $group, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if ($roleId !== null) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', ['orders.amend', 'restaurants.toggle_amendments'])->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['amendment_status', 'answer_window_started_at']));
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('amendments_enabled'));
        Schema::dropIfExists('order_amendments');
    }
};
