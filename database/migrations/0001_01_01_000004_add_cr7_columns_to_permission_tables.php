<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label_ar')->nullable()->after('name');
            $table->string('label_en')->nullable()->after('label_ar');
            $table->text('description')->nullable()->after('label_en');
            $table->boolean('is_system')->default(false)->after('description');
            $table->foreignId('created_by')->nullable()->after('is_system')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('group')->nullable()->after('name');
            $table->string('label_ar')->nullable()->after('group');
            $table->string('label_en')->nullable()->after('label_ar');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['label_ar', 'label_en', 'description', 'is_system']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['group', 'label_ar', 'label_en']);
        });
    }
};
