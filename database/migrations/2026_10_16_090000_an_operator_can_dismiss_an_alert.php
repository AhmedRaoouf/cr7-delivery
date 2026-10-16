<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One operator hiding one alert from their own dock; nothing about the order or complaint changes. */
    public function up(): void
    {
        Schema::create('alert_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_dismissals');
    }
};
