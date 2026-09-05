<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The national ID has two sides, and both are onboarding evidence.
 *
 * `captain_applications` carried one `national_id_file`, and `driver_documents.type`
 * had one `national_id`. A single photo of a card is half a document: the front
 * carries the name and the number, the back carries the address and the expiry, and an
 * operator checking whether a captain is who they say they are needs the pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captain_applications', function (Blueprint $table) {
            $table->string('national_id_back_file')->nullable()->after('national_id_file');
        });

        // MySQL enums are rewritten whole. `national_id` stays the front, so no existing
        // row changes meaning and nothing has to be backfilled.
        DB::statement(
            "ALTER TABLE driver_documents MODIFY COLUMN type
             ENUM('national_id','national_id_back','license','vehicle_license','criminal_record','other') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement("DELETE FROM driver_documents WHERE type = 'national_id_back'");
        DB::statement(
            "ALTER TABLE driver_documents MODIFY COLUMN type
             ENUM('national_id','license','vehicle_license','criminal_record','other') NOT NULL"
        );

        Schema::table('captain_applications', function (Blueprint $table) {
            $table->dropColumn('national_id_back_file');
        });
    }
};
