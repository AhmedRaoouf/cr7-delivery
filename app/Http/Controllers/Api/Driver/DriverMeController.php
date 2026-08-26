<?php

namespace App\Http\Controllers\Api\Driver;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — GET /driver/me, POST /driver/status, POST /driver/location. */
class DriverMeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->with(['city', 'assignedZones', 'activeOrder'])->firstOrFail();

        return ApiResponse::success($driver);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate(['is_online' => ['required', 'boolean']]);
        $driver = $request->user()->driver()->firstOrFail();

        if (! $driver->is_active) {
            throw new ApiException('account_suspended', 'حسابك موقوف حاليًا', 423);
        }

        if ($validated['is_online'] && $driver->active_order_id) {
            throw new ApiException('driver_already_busy', 'لا يمكن الاتصال بالإنترنت أثناء وجود طلب نشط', 422);
        }

        $driver->update(['is_online' => $validated['is_online']]);

        return ApiResponse::success(['is_online' => $driver->is_online]);
    }

    public function location(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $body = $request->all();
        $points = array_is_list($body) ? $body : [$body];

        $validated = collect($points)->map(fn ($point) => validator($point, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            'recorded_at' => ['nullable', 'date'],
        ])->validate());

        foreach ($validated as $point) {
            $driver->locations()->create([
                'order_id' => $driver->active_order_id,
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'speed' => $point['speed'] ?? null,
                'heading' => $point['heading'] ?? null,
                'accuracy' => $point['accuracy'] ?? null,
                'recorded_at' => $point['recorded_at'] ?? now(),
            ]);
        }

        $last = $validated->last();
        $driver->update([
            'current_lat' => $last['latitude'],
            'current_lng' => $last['longitude'],
            'location_updated_at' => now(),
        ]);

        return ApiResponse::success(['location_updated_at' => $driver->location_updated_at]);
    }
}
