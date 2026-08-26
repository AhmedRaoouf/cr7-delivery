<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('rateable_type');
            $table->unsignedBigInteger('rateable_id');
            $table->tinyInteger('stars');
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'user_id', 'rateable_type', 'rateable_id'], 'ratings_order_user_rateable_unique');
            $table->index(['rateable_type', 'rateable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
