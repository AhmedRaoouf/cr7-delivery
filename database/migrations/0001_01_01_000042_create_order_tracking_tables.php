<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->enum('actor_type', ['customer', 'restaurant', 'driver', 'admin', 'system']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code')->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_group_id')->nullable()->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->enum('kind', ['note', 'call'])->default('note');
            $table->enum('called_party', ['customer', 'restaurant', 'driver'])->nullable();
            $table->enum('call_outcome', ['answered', 'no_answer', 'wrong_number', 'switched_off'])->nullable();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
        });

        Schema::create('order_driver_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->integer('round')->default(1);
            $table->decimal('distance_km', 8, 3)->default(0);
            $table->enum('result', [
                'sent', 'accepted', 'rejected', 'expired', 'superseded',
                'cancelled_after_accept', 'reassigned_by_admin', 'unassigned_by_admin',
            ])->default('sent');
            $table->string('reason_code')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'round']);
            $table->index(['driver_id', 'result', 'created_at']);
        });

        Schema::create('delivery_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->enum('reported_by', ['driver', 'customer', 'restaurant', 'system']);
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('category', [
                'customer_unreachable', 'wrong_address', 'accident', 'food_problem',
                'driver_unresponsive', 'payment', 'handover', 'other',
            ]);
            $table->text('description')->nullable();
            $table->json('attachments')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_issues');
        Schema::dropIfExists('order_driver_attempts');
        Schema::dropIfExists('order_notes');
        Schema::dropIfExists('order_status_history');
    }
};
