<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Five operator asks from 2026-09-07, and the columns each of them needs.
 *
 * **1. The home strip stops being a cuisine list.** «هتشيل برجر وبيتزا ومشويات وكشري
 * وحلويات دي وتحط مكانها 4 containers، الأول مطاعم، التاني صيدليات، التالت كافيهات،
 * الرابعة سوبرماركت، والخامس خضروات» — and «الأدمين ممكن يضيف اللي هو عايزه». Those are
 * not cuisines. A cuisine says what a kitchen cooks; these say what kind of shop it is,
 * and a pharmacy has no cuisine at all. `cuisines` stays exactly as it is — the menu
 * filters still use it — and `store_categories` is the new, admin-editable top level.
 *
 * **2. A restaurant is open around the clock unless it says otherwise.** «مواعيد المطعم
 * مفتوح يعني خليها 24 ساعة دي default». `restaurant_opening_hours` still holds a real
 * week for the shops that keep one; `is_24_7` short-circuits it, and is the default for
 * every row that exists today and every row created after this.
 *
 * **3. Registration grows a password.** The customer shell used to be OTP-only. It now
 * has «إنشاء حساب»: phone, one code, then a password the customer picks. The code is
 * verified in one request and the account created in the next, so the password never
 * travels beside an unverified phone — `follow_up_token` is what carries the proof
 * between the two, and it dies fifteen minutes after the code is consumed.
 *
 * **4. Scheduled orders.** «الكاستمر عايز الأوردر ده الساعة ستة يبدأ يتعمل». Deliberately
 * NOT an order in `pending` with a future date: an order that exists is an order a
 * restaurant can accept, a captain can be offered and a promo can be spent on, and none
 * of that may happen at 14:00 for an 18:00 delivery. The basket is snapshotted here and
 * the real `PlaceOrderAction` runs at the appointed minute — which also means the price
 * charged is the price at the time it is actually cooked.
 *
 * **5. An errand assignment becomes an offer.** «إسناد الأوردر وإسناد المشوار وإسناد أي
 * إسناد في الدنيا بتبقى عامل لازم يتبعت notification وصفحة قبول ورفض». Food orders got
 * this on 2026-09-08 (`order_driver_attempts.assigned_by`); errands did not, and an
 * operator's assignment landed on a captain as a fact. `offered_*` is the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            // The card art. `image` is the photograph the card is built on; `icon` is the
            // small mark used where there is no room for the card (the filter sheet, the
            // admin table). Both nullable — a category the operator adds at 2am with no
            // artwork yet still has to render.
            $table->string('image')->nullable();
            $table->string('icon')->nullable();
            // Read by the app to tint the card's scrim, so a new category the operator
            // adds looks deliberate rather than defaulting to the brand yellow every
            // other one already uses.
            $table->string('color', 9)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('store_category_id')->nullable()->after('service_zone_id')
                ->constrained('store_categories')->nullOnDelete();
            $table->boolean('is_24_7')->default(true)->after('is_open_manual');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('follow_up_token', 80)->nullable()->unique()->after('consumed_at');
            $table->timestamp('follow_up_expires_at')->nullable()->after('follow_up_token');
        });

        Schema::create('scheduled_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->timestamp('scheduled_for')->index();
            $table->string('status')->default('scheduled')->index();
            // The whole checkout body, verbatim, plus a snapshot of the basket as it read
            // when the customer scheduled it. The snapshot is what the dashboards show;
            // the payload is what `PlaceOrderAction` is handed at release.
            $table->json('payload');
            $table->json('basket_snapshot');
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->tinyInteger('restaurant_count')->default(1);
            $table->foreignId('order_group_id')->nullable()->constrained('order_groups')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The releaser's only query.
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'scheduled_for']);
        });

        // A scheduled order is released by rebuilding its basket and running the real
        // `CreateOrderAction` over it — same pricing, same snapshots, same 19 states. The
        // basket it is rebuilt into must not be the customer's live one, or a release at
        // 18:00 destroys whatever they happen to be shopping for at 18:00. `is_scratch`
        // is what keeps `$user->cart()` from ever returning it.
        Schema::table('carts', function (Blueprint $table) {
            $table->boolean('is_scratch')->default(false)->after('user_id')->index();
        });

        Schema::create('scheduled_order_restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scheduled_order_id', 'restaurant_id'], 'scheduled_order_restaurant_unique');
            $table->index('restaurant_id');
        });

        Schema::table('errand_requests', function (Blueprint $table) {
            $table->foreignId('offered_driver_id')->nullable()->after('assigned_by_user_id')
                ->constrained('drivers')->nullOnDelete();
            $table->foreignId('offered_by_user_id')->nullable()->after('offered_driver_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('offered_at')->nullable()->after('offered_by_user_id');
            // A captain who declined must not be handed the same errand by the next
            // sweep, and the operator has to be able to see who said no.
            $table->json('declined_driver_ids')->nullable()->after('offered_at');
        });
    }

    public function down(): void
    {
        Schema::table('errand_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_driver_id');
            $table->dropConstrainedForeignId('offered_by_user_id');
            $table->dropColumn(['offered_at', 'declined_driver_ids']);
        });

        Schema::dropIfExists('scheduled_order_restaurants');
        Schema::dropIfExists('scheduled_orders');

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('is_scratch');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn(['follow_up_token', 'follow_up_expires_at']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_category_id');
            $table->dropColumn('is_24_7');
        });

        Schema::dropIfExists('store_categories');
    }
};
