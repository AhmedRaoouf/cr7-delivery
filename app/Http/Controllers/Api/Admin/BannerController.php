<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBannerRequest;
use App\Models\Banner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/banners[/{id}]. */
class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Banner::query()->orderBy('sort_order')->get());
    }

    public function show(Banner $banner): JsonResponse
    {
        return ApiResponse::success($banner);
    }

    public function store(StoreBannerRequest $request): JsonResponse
    {
        $banner = Banner::create($request->validated());

        return ApiResponse::success($banner, 'تم إنشاء البانر بنجاح', status: 201);
    }

    public function update(StoreBannerRequest $request, Banner $banner): JsonResponse
    {
        $banner->update($request->validated());

        return ApiResponse::success($banner->fresh(), 'تم تحديث البانر بنجاح');
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();

        return ApiResponse::success(message: 'تم حذف البانر بنجاح');
    }
}
