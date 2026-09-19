<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCuisineRequest;
use App\Http\Requests\Admin\UpdateCuisineRequest;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use App\Support\Media\MediaPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        $cuisines = Cuisine::query()->orderBy('sort_order')->get();

        return ApiResponse::success($cuisines);
    }

    public function store(StoreCuisineRequest $request): JsonResponse
    {
        $cuisine = Cuisine::create($this->withPickedMedia($request->validated(), $request));

        return ApiResponse::success($cuisine, 'تم إنشاء الصنف بنجاح', status: 201);
    }

    public function update(UpdateCuisineRequest $request, Cuisine $cuisine): JsonResponse
    {
        $cuisine->update($this->withPickedMedia($request->validated(), $request));

        return ApiResponse::success($cuisine, 'تم تحديث الصنف بنجاح');
    }

    /**
     * Doc 71 — a cuisine's image and icon are plain URL strings, so a library pick is the
     * same write with the URL resolved server-side from `image_media` / `icon_media`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withPickedMedia(array $data, Request $request): array
    {
        foreach (['image' => MediaField::CuisineImage, 'icon' => MediaField::CuisineIcon] as $field => $media) {
            $ref = $request->input($field.'_media');

            if (is_string($ref) && $ref !== '') {
                $data[$field] = MediaPicker::resolve($request->user(), $media, $ref, null);
            }
        }

        return $data;
    }

    public function destroy(Cuisine $cuisine): JsonResponse
    {
        $cuisine->delete();

        return ApiResponse::success(message: 'تم حذف الصنف بنجاح');
    }
}
