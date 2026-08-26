<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCuisineRequest;
use App\Http\Requests\Admin\UpdateCuisineRequest;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        $cuisines = Cuisine::query()->orderBy('sort_order')->get();

        return ApiResponse::success($cuisines);
    }

    public function store(StoreCuisineRequest $request): JsonResponse
    {
        $cuisine = Cuisine::create($request->validated());

        return ApiResponse::success($cuisine, 'تم إنشاء الصنف بنجاح', status: 201);
    }

    public function update(UpdateCuisineRequest $request, Cuisine $cuisine): JsonResponse
    {
        $cuisine->update($request->validated());

        return ApiResponse::success($cuisine, 'تم تحديث الصنف بنجاح');
    }

    public function destroy(Cuisine $cuisine): JsonResponse
    {
        $cuisine->delete();

        return ApiResponse::success(message: 'تم حذف الصنف بنجاح');
    }
}
