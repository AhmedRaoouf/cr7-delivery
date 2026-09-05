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
        // `validated()`, not `string()`. The latter hands back a `Stringable`, and
        // `platform` and `app` are enum-cast columns: Eloquent calls `Enum::from()` on
        // the object and throws `ValueError`, so a registration that passed validation
        // answered 500. Invisible until the 422 in front of it was fixed, and invisible
        // then too — the app logs the failure and moves on rather than showing a
        // customer an error about a feature they never asked for.
        $validated = $request->validated();

        $device = $request->user()->deviceTokens()->updateOrCreate(
            ['device_id' => $validated['device_id']],
            [
                'token' => $validated['token'],
                'platform' => $validated['platform'],
                'app' => $validated['app'],
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
