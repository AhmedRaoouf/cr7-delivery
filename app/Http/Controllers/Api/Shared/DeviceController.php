<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreDeviceRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §2 — POST/DELETE /devices: register or unregister an FCM token, tied to X-Device-Id. */
class DeviceController extends Controller
{
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $device = $request->user()->deviceTokens()->updateOrCreate(
            ['device_id' => $request->string('device_id')],
            [
                'token' => $request->string('token'),
                'platform' => $request->string('platform'),
                'app' => $request->string('app'),
                'last_used_at' => now(),
            ],
        );

        return ApiResponse::success($device, 'تم تسجيل الجهاز بنجاح');
    }

    public function destroy(Request $request): JsonResponse
    {
        $deviceId = $request->header('X-Device-Id') ?? $request->string('device_id')->value();

        $request->user()->deviceTokens()->where('device_id', $deviceId)->delete();

        return ApiResponse::success(message: 'تم إلغاء تسجيل الجهاز بنجاح');
    }
}
