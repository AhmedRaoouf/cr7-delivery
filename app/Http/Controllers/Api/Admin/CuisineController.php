<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCuisineRequest;
use App\Http\Requests\Admin\UpdateCuisineRequest;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use App\Support\Catalog\CuisineWriter;
use App\Support\FileUploader;
use App\Support\Media\MediaPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        // Doc 82 — how many dishes each holds, and «أخرى» (`is_fallback`) last.
        $cuisines = Cuisine::query()->withCount('products')->orderBy('is_fallback')->orderBy('sort_order')->get();

        return ApiResponse::success($cuisines);
    }

    public function store(StoreCuisineRequest $request): JsonResponse
    {
        $data = $this->withPickedMedia($request->validated(), $request);

        // Doc 82 — a picture is required, and from a product form it arrives as a file.
        if ($request->hasFile('image')) {
            $data['image'] = FileUploader::storePublic($request->file('image'), 'cuisines');
        }

        abort_if(blank($data['image'] ?? null), 422, 'اختر صورة للتصنيف.');

        $cuisine = CuisineWriter::findOrCreate($data + ['name_en' => null], null, $created);

        if (! $created) {
            if ($request->hasFile('image')) {
                FileUploader::deletePublic($data['image'], holders: 0);
            }

            return ApiResponse::success($cuisine, 'هذا التصنيف موجود بالفعل، وتم اختياره.', ['existing' => true]);
        }

        // The writer sets the common fields; the admin form may also set these.
        $cuisine->fill(array_intersect_key($data, array_flip(['icon', 'sort_order', 'is_active'])))->save();

        return ApiResponse::success($cuisine->fresh(), 'تم إنشاء التصنيف بنجاح', ['existing' => false], 201);
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
        // Doc 82 — its dishes move to «أخرى»; «أخرى» itself cannot be deleted.
        if ($cuisine->is_fallback) {
            return ApiResponse::error('cuisine_is_fallback', 'لا يمكن حذف تصنيف «أخرى».', 409);
        }

        CuisineWriter::delete($cuisine);

        return ApiResponse::success(message: 'تم حذف التصنيف بنجاح');
    }
}
