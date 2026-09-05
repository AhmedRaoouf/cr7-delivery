<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\UpdateRestaurantMeRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET/PATCH /restaurant/me, POST /restaurant/open-status. */
class RestaurantMeController extends Controller
{
    use HandlesUploads;

    public function show(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->with(['city', 'serviceZone', 'cuisines'])->firstOrFail();

        return ApiResponse::success([
            ...$restaurant->toArray(),
            'is_open_now' => $restaurant->isOpenNow(),
            'pending_count' => $restaurant->orders()->where('status', 'pending')->count(),
        ]);
    }

    public function update(UpdateRestaurantMeRequest $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'logo', 'restaurants', $restaurant);
        $data = $this->withPublicUpload($data, $request, 'cover', 'restaurants', $restaurant);

        $restaurant->update($data);

        return ApiResponse::success($restaurant->fresh(), 'تم تحديث بيانات المطعم بنجاح');
    }

    public function openStatus(Request $request): JsonResponse
    {
        $validated = $request->validate(['is_open' => ['required', 'boolean']]);
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $restaurant->update(['is_open_manual' => $validated['is_open']]);

        $activeCount = $restaurant->orders()->whereNotIn('status', ['pending', 'delivered', 'cancelled'])->count();

        // `is_open_now` as well as the flag that was just written, because they can
        // disagree: switching «مفتوح» on outside today's window sets the manual flag and
        // changes nothing a customer sees. `RES-19` told the operator «المطعم مفتوح
        // الآن» and then kept the header on «مغلق», which reads as the switch being
        // broken rather than as the hours being the reason.
        return ApiResponse::success([
            'is_open_manual' => $restaurant->is_open_manual,
            'is_open_now' => $restaurant->fresh()->isOpenNow(),
            'active_orders_count' => $activeCount,
        ]);
    }

    public function openingHours(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        return ApiResponse::success($restaurant->openingHours()->orderBy('weekday')->get());
    }

    public function updateOpeningHours(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
        ]);

        $restaurant = $request->user()->restaurant()->firstOrFail();

        foreach ($validated['hours'] as $entry) {
            $restaurant->openingHours()->updateOrCreate(
                ['weekday' => $entry['weekday']],
                ['opens_at' => $entry['opens_at'] ?? null, 'closes_at' => $entry['closes_at'] ?? null, 'is_closed' => $entry['is_closed'] ?? false],
            );
        }

        return ApiResponse::success($restaurant->openingHours()->orderBy('weekday')->get(), 'تم تحديث ساعات العمل بنجاح');
    }
}
