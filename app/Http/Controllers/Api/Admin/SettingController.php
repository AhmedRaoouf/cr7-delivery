<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Otp\SmsGatewayStatus;
use App\Support\ApiResponse;
use App\Support\Settings;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 08 §7 — GET/PATCH /admin/settings. Global-scope admins only.
 *
 * D-113. The web panel posted `{settings: {…}}` and this loop read the top level, found
 * one key called `settings`, matched no row, skipped it and answered «تم تحديث الإعدادات
 * بنجاح» — so nothing the web panel ever sent was written. It also skipped any key it
 * did not recognise in silence, which is how five keys the table never held sat on the
 * form for months. Both shapes are read now, every key is checked against
 * {@see SettingsCatalog} before anything is written, and a key the catalogue does not
 * know is refused by name.
 */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'));
    }

    /** The panel both clients draw — groups, fields, types, ranges and current values. */
    public function schema(): JsonResponse
    {
        return ApiResponse::success(SettingsCatalog::schema());
    }

    /** The SMS gateway as the server sees it — read-only, for the top of «التحقق والرسائل». */
    public function smsStatus(SmsGatewayStatus $status): JsonResponse
    {
        return ApiResponse::success($status->report());
    }

    public function update(Request $request): JsonResponse
    {
        $incoming = $request->input('settings');
        $updates = is_array($incoming) ? $incoming : $request->except(['settings']);

        $clean = SettingsCatalog::validate($updates);
        $before = [];

        foreach ($clean as $key => $value) {
            $field = SettingsCatalog::field($key);
            $setting = Setting::query()->firstOrNew(['key' => $key]);
            $before[$key] = $setting->exists ? $setting->value : null;

            $setting->fill([
                'value' => $value,
                'group' => $setting->group ?? $field['group'],
                'is_public' => (bool) ($field['public'] ?? false),
            ])->save();

            // {@see Settings::get} caches every key with `rememberForever`, so writing
            // the row is only half the edit. Without this the operator moves the split
            // to 20 on `ADM-46`, sees 20, and every captain keeps being paid at 15.
            Settings::forget($key);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'settings.manage',
            'auditable_type' => Setting::class,
            'auditable_id' => 0,
            'old_values' => $before,
            'new_values' => $clean,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::success(SettingsCatalog::schema(), 'تم حفظ الإعدادات');
    }
}
