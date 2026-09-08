<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «العرض المنبثق» — the card the customer meets on the home screen.
 *
 * Operator, 2026-09-08: «تخلي أول ما يخش على الهوم بيظهر له كرت كده فيه علامة X، بيكون
 * عرض من العروض مثلاً. دي برضه الأدمن بيحددها… الأدمين بيحدد شكله من الداشبورد، فاديله
 * الأكسيس إن هو يعمل أي حاجة فيه، يحط زرار، يحط زرارين، يظبط كل حاجة، الصورة، الكلام على
 * الصورة… وتخليه أصلا إن هو يظهر ولا ما يظهرش، وممكن يظهر كرت، يعمل X يظهر واحد تاني،
 * يظهر كذا واحد يعني ورا بعض. بس هو خلي الديفولت واحد… وطبعا لما يدوس عليه يروح في المكان
 * الصح بتاعه وتبقى ربطه بالحاجات اللي جوا برضه زي العروض».
 *
 * Three things that reading follows from, and that the columns exist to hold:
 *
 * **1. It is a queue, not a card.** «يظهر كذا واحد يعني ورا بعض» — the endpoint returns
 * every live popup in `sort_order`, and the app shows them one after the other. The
 * «default one» the operator asks for is a seeding decision, not a schema one: ship a
 * single active row and the queue is a queue of one.
 *
 * **2. Zero, one or two buttons.** A popup with no `primary_label_ar` is art plus the X,
 * which is the whole point of «يحط زرار، يحط زرارين» — the number of buttons is content,
 * so it cannot be a column the dashboard has to be redeployed to change.
 *
 * **3. It goes where a banner goes.** «تبقى ربطه بالحاجات اللي جوا برضه زي العروض». The
 * destination catalogue is `banners.link_type`'s, verbatim, so the app's existing
 * `BannerDestination` resolver takes a popup without learning anything — `HomePopupResource`
 * flattens the columns below into the same `link_type` / `link_value` pair a banner
 * returns. Stored as real foreign keys rather than a `link_value` string because a
 * banner pointing at a deleted dish keeps pointing at it; `nullOnDelete` here turns a
 * deleted product into a popup with no destination, which is a card the customer can
 * still read and dismiss.
 *
 * A normal `$table->enum(...)` is used, unlike `2026_09_10_090000_banner_can_point_at_a_product.php`:
 * doctrine/dbal is absent, so `->change()` on an existing enum drops its values and has
 * to be raw SQL — but this is a fresh `create`, exactly like `banners` in
 * `0001_01_01_000060_create_marketing_tables.php`, and there `enum()` is the convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_popups', function (Blueprint $table) {
            $table->id();
            // Every string is nullable: a popup may be a picture with a close button and
            // nothing else, and «الكلام على الصورة» is often already burnt into the art.
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('image')->nullable();

            // The same six destinations `banners.link_type` carries, in the same order.
            $table->enum('link_type', ['restaurant', 'product', 'category', 'promo', 'url', 'none'])->default('none');
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('store_category_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('url')->nullable();

            // «يحط زرار، يحط زرارين» — the primary is the call to action («اطلب الآن»),
            // the secondary is the polite way out («ليس الآن»). Neither is required.
            $table->string('primary_label_ar')->nullable();
            $table->string('primary_label_en')->nullable();
            $table->string('secondary_label_ar')->nullable();
            $table->string('secondary_label_en')->nullable();
            // What the second button does. `dismiss` closes this card exactly like the X;
            // `snooze` closes it and holds the whole queue back until tomorrow; `link`
            // sends it to the same destination as the primary.
            $table->enum('secondary_action', ['dismiss', 'snooze', 'link'])->default('dismiss');

            // How often one customer meets this card. The default is the one that does
            // not wear a returning customer down — once a day, not once a session.
            $table->enum('frequency', ['always', 'once_per_day', 'once'])->default('once_per_day');

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // `#RRGGBB` or `#RRGGBBAA`, like `store_categories.color`: the accent the app
            // tints the card's buttons with, so a Ramadan offer does not have to be the
            // brand yellow the other twenty screens already are.
            $table->string('theme', 9)->nullable();

            $table->timestamps();
            // Soft-deleted so a seasonal card can come back next year with its own
            // artwork rather than being retyped.
            $table->softDeletes();

            // The public endpoint's only query.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_popups');
    }
};
