<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Models\StaticPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/pages[/{slug}]. */
class PageController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(StaticPage::query()->get());
    }

    public function show(string $slug): JsonResponse
    {
        return ApiResponse::success(StaticPage::query()->where('slug', $slug)->firstOrFail());
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $page = StaticPage::create($request->validated());

        return ApiResponse::success($page, 'تم إنشاء الصفحة بنجاح', status: 201);
    }

    public function update(StorePageRequest $request, string $slug): JsonResponse
    {
        $page = StaticPage::query()->where('slug', $slug)->firstOrFail();
        $page->update($request->validated());

        return ApiResponse::success($page->fresh(), 'تم تحديث الصفحة بنجاح');
    }

    public function destroy(string $slug): JsonResponse
    {
        StaticPage::query()->where('slug', $slug)->firstOrFail()->delete();

        return ApiResponse::success(message: 'تم حذف الصفحة بنجاح');
    }
}
