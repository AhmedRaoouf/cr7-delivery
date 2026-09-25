<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use App\Support\Catalog\CuisineWriter;
use App\Support\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doc 82 — `GET/POST /restaurant/cuisines`: the list a product form chooses from, and
 * «تصنيف جديد» from inside that form.
 *
 * The list is shared by every place — a cuisine is the customer's filter, not a
 * section of one menu — and «أخرى» (`is_fallback`) is in it, last. A cuisine a place
 * creates needs a name and a picture, because it becomes a chip on the customer home
 * the moment it holds a dish.
 */
class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        $cuisines = Cuisine::query()
            ->where('is_active', true)
            ->orderBy('is_fallback')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name_ar', 'name_en', 'image', 'icon', 'is_fallback']);

        return ApiResponse::success($cuisines);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'min:2', 'max:60'],
            'name_en' => ['nullable', 'string', 'max:60'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'name_ar.required' => 'اكتب اسم التصنيف.',
            'image.required' => 'اختر صورة للتصنيف.',
        ]);

        $restaurant = $request->user()->restaurant()->firstOrFail();

        $url = FileUploader::storePublic($request->file('image'), 'cuisines');
        $cuisine = CuisineWriter::findOrCreate([...$data, 'image' => $url], $restaurant, $created);

        if (! $created) {
            FileUploader::deletePublic($url, holders: 0);

            return ApiResponse::success($cuisine, 'هذا التصنيف موجود بالفعل، وتم اختياره.', ['existing' => true]);
        }

        return ApiResponse::success($cuisine, 'تمت إضافة التصنيف.', ['existing' => false], 201);
    }
}
