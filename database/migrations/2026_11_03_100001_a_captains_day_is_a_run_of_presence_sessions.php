<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * doc 99 §2 — D-170. A captain's day is check-in, connection, gaps and last seen.
     *
     * One row per unbroken stretch of «online and heard from». A row is **open** while
     * `ended_at` is null; `open_driver_id` is a stored generated column that equals
     * `driver_id` only on the open row, and its unique index is what makes «one open
     * session per captain» a fact of the database rather than of the code — two requests
     * racing to open one cannot both win. Sessions never overlap, so a day's seconds are
     * never counted twice.
     */
    public function up(): void
    {
        Schema::create('driver_presence_sessions', function (Blueprint $table) {
            $table->id();
            // No foreign key: MySQL refuses a cascading key on a column a stored generated
            // column (`open_driver_id`) is computed from, and a captain is soft-deleted, so
            // his days stay readable after he leaves.
            $table->unsignedBigInteger('driver_id');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            // went_offline | signal_lost | logged_out | admin | blocked
            $table->string('end_reason', 20)->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->boolean('start_in_coverage')->nullable();
            $table->unsignedBigInteger('start_zone_id')->nullable();
            $table->unsignedBigInteger('open_driver_id')->nullable()->storedAs('IF(`ended_at` IS NULL, `driver_id`, NULL)');
            $table->timestamps();

            $table->unique('open_driver_id', 'driver_presence_one_open_per_captain');
            $table->index(['driver_id', 'started_at']);
            $table->index(['ended_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_presence_sessions');
    }
};
