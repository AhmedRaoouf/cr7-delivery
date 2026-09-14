<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-100, the transition — a phone says whether it can ring.
 *
 * The ring is a **data-only** FCM message: the app's own background handler draws the
 * looping notification. An app built before doc 62 has an empty background handler, so a
 * ring sent to it while it is in the background or closed draws **nothing at all** — a
 * place on the old APK would hear about no new order until someone updated the phone.
 *
 * The server could not tell the two builds apart: a device row carried a token, a
 * platform and an app, and no version. Now the app says what it can do when it registers
 * its token; a phone that says nothing is the old build, and gets the ordinary push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('app');
            $table->string('app_version', 20)->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn(['capabilities', 'app_version']);
        });
    }
};
