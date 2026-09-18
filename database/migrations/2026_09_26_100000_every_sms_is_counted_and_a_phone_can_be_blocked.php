<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 68 — «المهم تحافظ علي الفلوس».
 *
 * Every verification code the platform answers is a row in `sms_sends`, whether it cost
 * money (`real`), was the operator's test code (`test`) or was the anti-enumeration
 * stand-in for a number that holds no account (`shadow`). Every cap — per phone, per
 * address, per device and the platform's own daily budget — is a count over this table,
 * so a cache clear, a deploy or a restart cannot hand anybody a fresh allowance.
 *
 * `otp_blocks` holds a number the platform has stopped sending to: the daily cap went
 * past, too many codes were guessed dead, or an operator said so. A block is lifted by
 * writing `lifted_at`, never by deleting the row, so the history of who was blocked and
 * who let them out stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_sends', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('purpose', 32);
            // real · test · shadow — only `real` reaches the gateway and the budget.
            $table->string('kind', 8);
            // The caller's address — an IPv6 caller is counted by its /64, which is what
            // one subscriber holds.
            $table->string('ip', 45)->nullable();
            $table->string('device_id', 64)->nullable();
            // The code this send issued was guessed dead (`otp.max_attempts` wrong tries).
            $table->timestamp('burned_at')->nullable();
            // A correct code for this phone and purpose — the escalating wait starts over.
            $table->timestamp('settled_at')->nullable();
            // An operator unblocked the number — the phone's own counters start over.
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['phone', 'created_at']);
            $table->index(['phone', 'purpose', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index(['kind', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('otp_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            // daily_cap · burned_codes · admin · abuse
            $table->string('reason', 16);
            $table->timestamp('blocked_until');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['phone', 'blocked_until']);
            $table->index('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_blocks');
        Schema::dropIfExists('sms_sends');
    }
};
