<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromoCodeRequest;
use App\Http\Requests\Admin\UpdatePromoCodeRequest;
use App\Models\PromoCode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/promo-codes[/{id}], GET /{id}/usages. */
class PromoCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $codes = PromoCode::query()
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($codes->items(), meta: [
            'current_page' => $codes->currentPage(),
            'per_page' => $codes->perPage(),
            'total' => $codes->total(),
            'last_page' => $codes->lastPage(),
        ]);
    }

    public function show(PromoCode $promoCode): JsonResponse
    {
        return ApiResponse::success($promoCode);
    }

    public function store(StorePromoCodeRequest $request): JsonResponse
    {
        $promoCode = PromoCode::create($request->validated());

        return ApiResponse::success($promoCode, 'تم إنشاء كود الخصم بنجاح', status: 201);
    }

    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode): JsonResponse
    {
        $promoCode->update($request->validated());

        return ApiResponse::success($promoCode->fresh(), 'تم تحديث كود الخصم بنجاح');
    }

    public function destroy(PromoCode $promoCode): JsonResponse
    {
        $promoCode->delete();

        return ApiResponse::success(message: 'تم حذف كود الخصم بنجاح');
    }

    public function usages(Request $request, PromoCode $promoCode): JsonResponse
    {
        $usages = $promoCode->usages()->with(['user', 'order'])->latest()->paginate($request->integer('per_page', 20));

        return ApiResponse::success($usages->items(), meta: [
            'current_page' => $usages->currentPage(),
            'per_page' => $usages->perPage(),
            'total' => $usages->total(),
            'last_page' => $usages->lastPage(),
        ]);
    }
}
