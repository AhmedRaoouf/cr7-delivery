<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 64 / D-120 — 2026-09-16. The refresh token.
 *
 * One row per refresh token ever issued. A sign-in starts a **family** (a uuid); every
 * renewal adds a row to it and marks the one it replaced `rotated_at`, so the family is
 * the whole history of one sign-in on one device, and revoking a sign-in is one
 * `where('family', …)`.
 *
 * - `token_hash` — sha256 of the plain token. The plain token is never stored, except
 *   for the successor pair inside `grace_payload` (below).
 * - `access_token_id` — the `personal_access_tokens` row issued beside it. Nullable, and
 *   nulled by the database when that row goes: the access token is deleted on rotation and
 *   pruned by `sanctum:prune-expired` a day after it expires, and neither may leave a
 *   dangling id behind. Nothing is decided on it: whether a pair was ever held is read
 *   from the refresh token alone (`rotated_at`, `last_used_at`), see `SessionIssuer`.
 * - `grace_payload` — the successor pair, encrypted with `Crypt::encryptString`, written at
 *   rotation and read only for the next ‪120‬ s. It is what lets five requests that all
 *   found the access token expired renew at once and get one pair between them. Nulled
 *   once stale, by the next rotation in the family and by `cr7:prune-sessions`.
 * - `replaced_by_id` — the row this one was rotated into. Self-referencing and nulled on
 *   delete, so pruning an old row never breaks the chain's newer end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family')->index();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('access_token_id')->nullable()
                ->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('client', 20)->nullable();
            $table->string('device_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('rotated_at')->nullable()->index();
            $table->foreignId('replaced_by_id')->nullable()
                ->constrained('refresh_tokens')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->text('grace_payload')->nullable();
            // When this refresh token was last presented — a renewal or a replay.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
