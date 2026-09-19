<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-160 — the reusable media library (doc 71).
 *
 * Deliberately **no foreign key points from these tables at a business entity that can
 * cascade into them**: an asset is referenced by URL from `products.image`,
 * `menu_categories.image`, `restaurants.cover` … and never the other way round, so
 * deleting a product, a category, a place or a user cannot reach a row here. The only
 * cascades are inside the library itself (a hard-deleted asset takes its tag links and
 * audience rules with it) and `place_group_restaurant`, which is membership, not media.
 * `created_by` is `nullOnDelete` for the same reason.
 *
 * Assets are soft-deleted: «remove from the library» keeps the file and the row, so a
 * product that still points at the picture keeps showing it, and a shipped asset that was
 * removed comes back to «Available app images» instead of vanishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();
            $table->string('filename', 255);
            // `public` = the configured uploads disk (stable `media-library/` folder);
            // `web` = a file committed under public_path(), served as it is.
            $table->string('disk', 20)->default('public');
            $table->string('path', 500);
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->char('sha1', 40)->nullable()->index();
            $table->enum('source', ['uploaded', 'shipped', 'system'])->default('uploaded')->index();
            // The registry key a shipped/system asset was made from — makes «add» and the
            // manifest rebuild idempotent.
            $table->string('source_key', 191)->nullable()->unique();
            $table->string('system_key', 60)->nullable()->unique();
            $table->boolean('is_protected')->default(false);
            $table->json('usage_types');
            $table->enum('visibility', ['global', 'restricted', 'hidden'])->default('global');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['visibility', 'deleted_at']);
            $table->index('created_at');
        });

        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('media_asset_tag', function (Blueprint $table) {
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('media_tags')->cascadeOnDelete();
            $table->primary(['media_asset_id', 'media_tag_id']);
        });

        // Who may see a `restricted` asset. `audience_id` names a restaurant, a place group
        // or a store category by `audience_type` — no FK, on purpose (see the header).
        Schema::create('media_asset_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->enum('audience_type', ['place', 'group', 'store_category']);
            $table->unsignedBigInteger('audience_id');
            $table->timestamps();

            $table->unique(['media_asset_id', 'audience_type', 'audience_id'], 'media_audience_unique');
            $table->index(['audience_type', 'audience_id']);
        });

        Schema::create('place_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('place_group_restaurant', function (Blueprint $table) {
            $table->foreignId('place_group_id')->constrained('place_groups')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->primary(['place_group_id', 'restaurant_id']);
        });

        // The two permissions. `PermissionsSeeder` is `firstOrCreate` but a deploy runs
        // `migrate`, not the seeders — so the rows are created here too, and handed to
        // the roles that hold every permission.
        $now = now();
        foreach (['media.view', 'media.manage'] as $name) {
            $id = \Illuminate\Support\Facades\DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

            if ($id === null) {
                $id = \Illuminate\Support\Facades\DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => 'web', 'group' => 'media', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $roleId = \Illuminate\Support\Facades\DB::table('roles')->where('name', 'super_admin')->value('id');

            if ($roleId !== null) {
                \Illuminate\Support\Facades\DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $id, 'role_id' => $roleId,
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // The protected default picture and the system tags, from the registry — the same
        // idempotent call `php artisan media:sync` makes.
        \App\Support\Media\MediaLibrarySync::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('place_group_restaurant');
        Schema::dropIfExists('place_groups');
        Schema::dropIfExists('media_asset_audiences');
        Schema::dropIfExists('media_asset_tag');
        Schema::dropIfExists('media_tags');
        Schema::dropIfExists('media_assets');
    }
};
