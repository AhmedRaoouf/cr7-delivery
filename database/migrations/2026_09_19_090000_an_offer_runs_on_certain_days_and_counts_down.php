<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an offer has that a popup card did not — «العروض».
 *
 * Operator, 2026-09-10: «عايزين نضيف عروض للمطاعم زى العروض المنبثقه كده ويكون فيها عداد
 * تنازلى لو العرض مثلا 3 ايام… مثال 2 بيتزا لارج والثلاثه مجانا. العرض مثلا خميس وجمعه
 * وسبت».
 *
 * **1. `days_of_week`.** «خميس وجمعة وسبت» is not a window — it is a *pattern inside* a
 * window. `starts_at`/`ends_at` can say «this weekend» once; they cannot say «every
 * weekend until the end of the month», and a weekly offer entered as a window has to be
 * re-entered every seven days by a human who will eventually forget. A null column keeps
 * every existing card running every day, which is what it does today.
 *
 * Stored as JSON rather than a bitmask: seven integers in a column an operator can read
 * in phpMyAdmin beat a `0b1110000` nobody can. `0 = Sunday … 6 = Saturday`, matching
 * PHP's `date('w')` and Dart's… no — Dart's `DateTime.weekday` is `1 = Monday … 7 =
 * Sunday`, so the app converts. The API's convention is the one written here and the
 * clients bend to it, because the column is the thing two clients have to agree about.
 *
 * **2. `show_countdown`.** «يكون فيها عداد تنازلى لو العرض مثلا 3 ايام». Not derived from
 * `ends_at` being set: a card that runs until the end of the season also has an `ends_at`
 * and a ticking clock on it would be absurd. Whether the urgency is real is the
 * operator's call, so it is a switch, and it is off by default — the cards already live
 * do not start counting down the morning this deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_popups', function (Blueprint $table) {
            // Null = every day. An empty array would mean «never», which is a state the
            // dashboard must not be able to save by accident, so the request rejects it.
            $table->json('days_of_week')->nullable()->after('frequency');
            $table->boolean('show_countdown')->default(false)->after('days_of_week');
        });
    }

    public function down(): void
    {
        Schema::table('home_popups', function (Blueprint $table) {
            $table->dropColumn(['days_of_week', 'show_countdown']);
        });
    }
};
