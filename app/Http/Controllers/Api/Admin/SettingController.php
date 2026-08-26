<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET/PATCH /admin/settings {key: value, ...}. Global-scope admins only. */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'));
    }

    public function update(Request $request): JsonResponse
    {
        $updates = $request->all();
        $before = [];

        foreach ($updates as $key => $value) {
            $setting = Setting::query()->where('key', $key)->first();

            if (! $setting) {
                continue;
            }

            $before[$key] = $setting->value;
            $setting->update(['value' => $value]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'settings.manage',
            'auditable_type' => Setting::class,
            'auditable_id' => 0,
            'old_values' => $before,
            'new_values' => $updates,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::success(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'), 'تم تحديث الإعدادات بنجاح');
    }
}
