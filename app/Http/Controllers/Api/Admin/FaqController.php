<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Models\Faq;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/faqs[/{id}]. */
class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Faq::query()->orderBy('sort_order')->get());
    }

    public function store(StoreFaqRequest $request): JsonResponse
    {
        $faq = Faq::create($request->validated());

        return ApiResponse::success($faq, 'تم إنشاء السؤال بنجاح', status: 201);
    }

    public function update(StoreFaqRequest $request, Faq $faq): JsonResponse
    {
        $faq->update($request->validated());

        return ApiResponse::success($faq->fresh(), 'تم تحديث السؤال بنجاح');
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return ApiResponse::success(message: 'تم حذف السؤال بنجاح');
    }
}
