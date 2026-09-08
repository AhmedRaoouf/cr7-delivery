<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cuisine gets a picture.
 *
 * The filter sheet and the search screen drew the fifteen cuisines as bare text chips —
 * «الألوان، المطبخ، برجر، بيتزا، مشويات، سوشي، حط برضه لكل واحدة صورة… ما تبقاش متسبة
 * كده مجرد كلام». `icon` already exists and is a **slug** (`burger`, `koshari`), which
 * the client resolves to a bundled 24 pt mark; it is not artwork and cannot be one,
 * because it is a word rather than a file.
 *
 * So `image` is the photograph the card is built on, exactly like
 * `store_categories.image` — nullable, because a cuisine the operator adds at 2am has
 * no artwork yet and its chip still has to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuisines', function (Blueprint $table) {
            $table->string('image')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('cuisines', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
