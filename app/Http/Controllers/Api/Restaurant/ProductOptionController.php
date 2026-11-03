<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreProductOptionRequest;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Support\ApiResponse;
use App\Support\Catalog\OptionGroupWriter;
use App\Support\Catalog\PriceApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/option-groups/{gid}/options[/{oid}],
 * PUT …/options/order. The rules are {@see OptionGroupWriter}'s (doc 76 §2).
 */
class ProductOptionController extends Controller
{
    public function index(Request $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $optionGroup);

        return ApiResponse::success($this->options($optionGroup));
    }

    public function store(StoreProductOptionRequest $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $optionGroup);
        $option = OptionGroupWriter::saveOption($optionGroup, null, $request->validated());

        return ApiResponse::success($option, 'تم إنشاء الخيار بنجاح', status: 201);
    }

    public function update(StoreProductOptionRequest $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureOwned($request, $optionGroup);
        abort_unless($option->product_option_group_id === $optionGroup->id, 404);

        $data = $request->validated();
        $note = $data['price_note'] ?? null;
        unset($data['price_note']);

        // doc 99 §1 — D-169: an existing option's price waits for the office like the
        // dish's own. A cleared field means «adds nothing», exactly as the writer reads it.
        $delta = null;

        if (PriceApproval::enabled() && array_key_exists('price_delta', $data)) {
            $sent = round((float) ($data['price_delta'] ?? 0), 2);

            if (abs($sent - round((float) $option->price_delta, 2)) >= 0.005) {
                $delta = $sent;
            }

            unset($data['price_delta']);
        }

        $option = OptionGroupWriter::saveOption($optionGroup, $option, $data);

        $filed = $delta === null
            ? null
            : PriceApproval::file($optionGroup->product, $request->user(), null, [$option->id => $delta], $note);

        $payload = $option->toArray() + [
            'pending_price_request' => $optionGroup->product?->openPriceRequest()->first()?->toPlaceSummary(),
        ];

        if ($filed === null) {
            return ApiResponse::success($payload, 'تم تحديث الخيار بنجاح');
        }

        return ApiResponse::success(
            $payload,
            'أُرسل السعر الجديد إلى الإدارة للمراجعة، وسيظهر للعملاء بعد الاعتماد',
            meta: ['price_request' => $filed['outcome']],
        );
    }

    public function order(Request $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $optionGroup);
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        OptionGroupWriter::reorder($optionGroup->options()->get(), $request->input('ids'));

        return ApiResponse::success($this->options($optionGroup), 'تم حفظ الترتيب');
    }

    public function destroy(Request $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureOwned($request, $optionGroup);
        abort_unless($option->product_option_group_id === $optionGroup->id, 404);
        $option->delete();

        return ApiResponse::success(message: 'تم حذف الخيار بنجاح');
    }

    private function options(ProductOptionGroup $optionGroup)
    {
        return $optionGroup->options()->orderBy('sort_order')->orderBy('id')->get();
    }

    private function ensureOwned(Request $request, ProductOptionGroup $optionGroup): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $optionGroup->product?->restaurant_id === $restaurant->id, 404);
    }
}
