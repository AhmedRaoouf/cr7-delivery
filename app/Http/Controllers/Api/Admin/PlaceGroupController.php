<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\MediaAudience;
use App\Models\PlaceGroup;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 71 — hand-made groups of places («مطاعم الشاطئ»), so an asset can be shown to a
 * whole segment at once. Groups are global, like the library they serve.
 */
class PlaceGroupController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);

        $query = PlaceGroup::query()->withCount('restaurants');
        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        return $this->paginated($query->orderBy('name_ar')->paginate($params->perPage(50)));
    }

    public function show(PlaceGroup $placeGroup): JsonResponse
    {
        return ApiResponse::success($this->present($placeGroup));
    }

    public function store(Request $request): JsonResponse
    {
        $group = PlaceGroup::create($this->validated($request));
        AuditLogger::record($request->user(), 'place_groups.create', $group);

        return ApiResponse::success($this->present($group), 'تم إنشاء المجموعة', status: 201);
    }

    public function update(Request $request, PlaceGroup $placeGroup): JsonResponse
    {
        $placeGroup->update($this->validated($request, partial: true));
        AuditLogger::record($request->user(), 'place_groups.update', $placeGroup);

        return ApiResponse::success($this->present($placeGroup), 'تم تحديث المجموعة');
    }

    /** PUT /admin/place-groups/{group}/places — the whole membership in one write. */
    public function syncPlaces(Request $request, PlaceGroup $placeGroup): JsonResponse
    {
        $data = $request->validate([
            'restaurant_ids' => ['present', 'array', 'max:1000'],
            'restaurant_ids.*' => ['integer', 'exists:restaurants,id'],
        ]);

        $placeGroup->restaurants()->sync($data['restaurant_ids']);
        AuditLogger::record($request->user(), 'place_groups.members', $placeGroup, ['restaurant_ids' => $data['restaurant_ids']]);

        return ApiResponse::success($this->present($placeGroup), 'تم تحديث أماكن المجموعة');
    }

    public function destroy(Request $request, PlaceGroup $placeGroup): JsonResponse
    {
        // The rules that named this group go with it. An asset left `restricted` with no
        // audience is visible to nobody — the safe default, and the Admin sees it.
        MediaAudience::query()->where('audience_type', MediaAudience::GROUP)->where('audience_id', $placeGroup->id)->delete();
        AuditLogger::record($request->user(), 'place_groups.delete', $placeGroup);
        $placeGroup->delete();

        return ApiResponse::success(message: 'تم حذف المجموعة');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name_ar' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:120'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(PlaceGroup $group): array
    {
        return [
            'id' => $group->id,
            'name_ar' => $group->name_ar,
            'name_en' => $group->name_en,
            'description' => $group->description,
            'restaurants' => $group->restaurants()->orderBy('name_ar')->get(['restaurants.id', 'name_ar', 'name_en']),
            'restaurants_count' => $group->restaurants()->count(),
        ];
    }
}
