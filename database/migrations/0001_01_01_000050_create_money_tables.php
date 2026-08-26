<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('method', ['cash', 'vodafone_cash', 'instapay']);
            $table->enum('provider', ['manual', 'paymob', 'fawry'])->default('manual');
            $table->enum('status', [
                'pending', 'awaiting_proof', 'under_review', 'paid',
                'failed', 'refunded', 'partially_refunded',
            ])->default('pending');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('collected_amount', 12, 2)->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('gate_opened_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->boolean('reminder_sent')->default(false);
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index('order_group_id');
            $table->index(['status', 'method', 'created_at']);
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('image');
            $table->string('reference')->nullable();
            $table->decimal('amount_claimed', 12, 2)->nullable();
            $table->string('sender_number')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('payment_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['full', 'partial']);
            $table->string('reason_code')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->enum('method', ['cash', 'vodafone_cash', 'instapay', 'wallet'])->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->enum('party_type', ['restaurant', 'driver']);
            $table->unsignedBigInteger('party_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('refunds_total', 12, 2)->default(0);
            $table->decimal('commission_total', 12, 2)->default(0);
            $table->decimal('delivery_fees_total', 12, 2)->default(0);
            $table->decimal('cash_collected', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->enum('status', ['draft', 'pending', 'paid'])->default('draft');
            $table->string('payment_reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['party_type', 'party_id', 'period_start']);
        });

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->enum('type', ['order', 'direct_order_food_passthrough'])->default('order');
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('driver_earning', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->timestamps();

            $table->index('settlement_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('payments');
    }
};
