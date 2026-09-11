<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-11 — three operator rulings, as data.
 *
 * 1. **One phone, one account.** `device_tokens` was unique on `(user_id, token)`, so the
 *    same FCM token could sit under every account that had ever signed in on that phone —
 *    and `NotificationDispatcher` sends to every token a user has. Signing out of a
 *    captain account and into an admin one left the captain's offers arriving all night.
 *    Duplicates are collapsed to the most recently used row and the index is moved onto
 *    the token itself, so the class of bug cannot come back through another code path.
 *
 * 2. **D-75 — the platform's only income is 15 % of the delivery fee.** «عمولة المنصة
 *    اللي هو الأدمن كلها ‪15‬٪ على أي order من فلوس الديليفري، مش عايز حاجة غيرها.»
 *    Twenty-three places were on 10 % of the food and one on 15 %; `commission.default_rate`
 *    was 10. All of it goes to zero. `platform.order_fee` is already 0 (2026-09-15
 *    migration), so the delivery margin is now the whole of it.
 *
 * 3. **D-76 — two minutes.** «لو مفيش دليفري قبل الطلب بعد ‪2‬ دقايق من حالة الطلب جاهز،
 *    يروح إشعار للأدمن، وابعت إشعار للمطعم تواصل مع الدعم… وخلال دقيقتين لو محدش قبل يبعت
 *    للناس كلها تاني.» Both clocks were wrong for it: the rebroadcast ran every 60 s and
 *    only ever asked captains who had not been asked, and the escalation waited 300 s.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1 ─ one phone, one account
        $duplicates = DB::table('device_tokens')
            ->select('token')
            ->groupBy('token')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('token');

        foreach ($duplicates as $token) {
            $keep = DB::table('device_tokens')
                ->where('token', $token)
                ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
                ->orderByDesc('id')
                ->value('id');

            DB::table('device_tokens')->where('token', $token)->where('id', '!=', $keep)->delete();
        }

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->unique('token', 'device_tokens_token_unique');
        });

        // 2 ─ D-75: no commission on food, anywhere
        DB::table('restaurants')->update(['commission_rate' => 0]);
        self::setting('commission.default_rate', '0');

        // 3 ─ D-76: the two-minute rules
        self::setting('dispatch.rebroadcast_interval_seconds', '120');
        self::setting('dispatch.escalate_after_seconds', '120');

        // 4 ─ «على أي order»: an errand's fee splits like a delivery fee, and the split
        //     has to be snapshotted like an order's — the rate can move (D-70) and a
        //     completed ride must not re-price when it does.
        Schema::table('errand_requests', function (Blueprint $table) {
            $table->decimal('driver_earning', 10, 2)->default(0)->after('offered_fee');
            $table->decimal('platform_margin', 10, 2)->default(0)->after('driver_earning');
        });
    }

    public function down(): void
    {
        Schema::table('errand_requests', function (Blueprint $table) {
            $table->dropColumn(['driver_earning', 'platform_margin']);
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique('device_tokens_token_unique');
        });

        self::setting('commission.default_rate', '10');
        self::setting('dispatch.rebroadcast_interval_seconds', '60');
        self::setting('dispatch.escalate_after_seconds', '300');
        // The zeroed rates are deliberately **not** restored: nothing here knows what
        // each place used to charge, and inventing 10 % for all of them would be worse
        // than leaving the decision where the operator put it.
    }

    /**
     * `Settings::get` caches `rememberForever`, so a row written behind its back is a
     * number the code keeps not seeing — the defect doc 36 §10 found on `ADM-46`, where
     * moving the split 15 → 20 showed 20 and paid 15 until somebody ran `cache:clear`.
     * Writing through the helper busts the key as it goes.
     */
    private static function setting(string $key, string $value): void
    {
        $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
        $isPublic = (bool) DB::table('settings')->where('key', $key)->value('is_public');

        Settings::set($key, is_numeric($value) ? (float) $value : $value, $group, $isPublic);
    }
};
