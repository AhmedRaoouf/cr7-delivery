<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Catalog\PlaceOrder;
use App\Support\Http\PublicCache;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Doc 87 — «ترتيب الأماكن»: the order the customer meets the places in.
 *
 * One list for the whole platform, so both routes are `admin.global`: a city-scoped
 * operator pinning their own places would be reordering everybody else's too.
 *
 *     GET /admin/places/order  → {mode, modes, places}
 *     PUT /admin/places/order  ← {mode, pinned_ids: [id, …]}  (first id = first card)
 *
 * `places` is **every** place in the order the app shows them when the customer has not
 * chosen a sort — inactive ones included and flagged, because the app simply skips them.
 */
class PlaceOrderController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->payload(PlaceOrder::mode()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(PlaceOrder::values())],
            'pinned_ids' => ['present', 'array', 'max:1000'],
            'pinned_ids.*' => ['integer', 'distinct', Rule::exists('restaurants', 'id')->whereNull('deleted_at')],
        ]);

        $ids = array_values(array_map('intval', $data['pinned_ids']));
        $before = [
            'mode' => PlaceOrder::mode(),
            'pinned_ids' => Restaurant::query()->whereNotNull('pin_position')->orderBy('pin_position')->pluck('id')->all(),
        ];

        DB::transaction(function () use ($ids, $data) {
            Restaurant::query()->whereNotNull('pin_position')->update(['pin_position' => null]);

            foreach ($ids as $index => $id) {
                Restaurant::query()->whereKey($id)->update(['pin_position' => $index + 1]);
            }

            Settings::set(PlaceOrder::SETTING, $data['mode'], 'catalog', false);
        });

        // A query-builder update fires no model event, so the stored catalogue pages are
        // retired here (the setting's own `saved` covers only a changed mode).
        PublicCache::bump('catalog');

        AuditLogger::record(
            $request->user(),
            'places.order',
            Setting::query()->where('key', PlaceOrder::SETTING)->firstOrFail(),
            ['mode' => $data['mode'], 'pinned_ids' => $ids],
            $before,
        );

        return ApiResponse::success($this->payload($data['mode']), 'تم حفظ الترتيب');
    }

    /** @return array<string, mixed> */
    private function payload(string $mode): array
    {
        $places = PlaceOrder::apply(
            Restaurant::query()->with(['storeCategory:id,name_ar,name_en', 'city:id,name_ar,name_en']),
            $mode,
        )->get();

        return [
            'mode' => $mode,
            'modes' => PlaceOrder::modes(),
            'places' => $places->map(fn (Restaurant $place) => [
                'id' => $place->id,
                'name_ar' => $place->name_ar,
                'name_en' => $place->name_en,
                'logo' => $place->logo,
                'is_active' => (bool) $place->is_active,
                'is_featured' => (bool) $place->is_featured,
                'rating_avg' => (float) $place->rating_avg,
                'orders_count' => (int) $place->orders_count,
                'pin_position' => $place->pin_position === null ? null : (int) $place->pin_position,
                'store_category' => $place->storeCategory?->only(['id', 'name_ar', 'name_en']),
                'city' => $place->city?->only(['id', 'name_ar', 'name_en']),
            ])->values()->all(),
        ];
    }
}
