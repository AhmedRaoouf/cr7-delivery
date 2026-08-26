<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('user_role', ['customer', 'driver', 'restaurant']);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_group_id')->nullable()->constrained('order_groups')->nullOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete();
            $table->enum('category', [
                'order_issue', 'late_delivery', 'missing_item', 'food_quality',
                'driver_behaviour', 'payment', 'app_problem', 'other',
            ]);
            $table->string('subject')->nullable();
            $table->text('description');
            $table->json('attachments')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved', 'rejected'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->boolean('visible_to_restaurant')->default(false);
            $table->timestamp('restaurant_first_read_at')->nullable();
            $table->timestamp('restaurant_replied_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->enum('resolution_outcome', [
                'refund', 'partial_refund', 'redelivery', 'warning_restaurant',
                'warning_driver', 'no_action', 'rejected',
            ])->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['restaurant_id', 'visible_to_restaurant', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('order_id');
        });

        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('sender_role', ['customer', 'driver', 'restaurant', 'admin']);
            $table->enum('visibility', ['all', 'internal'])->default('all');
            $table->text('message');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['complaint_id', 'visibility', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaints');
    }
};
