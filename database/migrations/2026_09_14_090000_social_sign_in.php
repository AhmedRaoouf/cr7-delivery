<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «المتابعة باستخدام Apple / Google» — the two buttons `CUS-07` has always drawn.
 *
 * Neither provider hands over a phone number. Apple returns a stable subject, an email
 * (often a `@privaterelay.appleid.com` alias) and — on the **first authorisation only** —
 * a full name. Google returns a subject, an email, a name and a photo; the phone lives on
 * the People API behind a scope Google does not grant to a consumer app and which most
 * accounts do not populate anyway.
 *
 * So a provider identity is never a complete CR7 account on its own: `users.phone` is
 * unique, not null, and is what the restaurant and the captain actually call. Two tables,
 * therefore, and not one column on `users`:
 *
 * **`social_accounts`** is the identity, after it has been attached to a real account. The
 * unique index on `(provider, provider_user_id)` is the rule «one provider identity is one
 * account»; the one on `(user_id, provider)` is its mirror — an account has at most one
 * Apple and at most one Google.
 *
 * **`social_link_tickets`** is the gap in between: a verified identity with no account yet.
 * It is the same shape as `otp_codes.follow_up_token` and for the same reason — the fact
 * has to survive between two requests, it has to die on its own clock, and it must be
 * spendable exactly once. Fifteen minutes, matching `VerifyOtpAction::REGISTRATION_TOKEN_MINUTES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['apple', 'google']);
            $table->string('provider_user_id');
            // Apple's is usually a private relay alias, and it is not a login on this
            // platform — `users.email` stays the account's own. Kept because it is the
            // only thing support can match when somebody says «دخلت بحساب Apple».
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('social_link_tickets', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['apple', 'google']);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            // Apple sends the name once and never again. If it is not written here on
            // that one pass it is gone for good, so it is carried across the phone step
            // rather than asked for a second time.
            $table->string('name')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('token', 80)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_link_tickets');
        Schema::dropIfExists('social_accounts');
    }
};
