<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromoCodeRequest;
use App\Http\Requests\Admin\UpdatePromoCodeRequest;
use App\Models\PromoCode;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/promo-codes[/{id}], GET /{id}/usages. */
class PromoCodeController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'used_count', 'starts_at', 'ends_at', 'code'], '-created_at');

        $query = PromoCode::query();

        if (($active = $params->bool('is_active')) !== null) {
            $query->where('is_active', $active);
        }

        $this->whereInList($query, 'type', $params->enum('type', ['fixed', 'percent', 'free_delivery']));
        $this->whereInList($query, 'cost_bearer', $params->enum('cost_bearer', ['platform', 'restaurant']));

        if (($states = $params->enum('state', ['live', 'scheduled', 'expired', 'inactive'])) !== []) {
            $now = now();
            $query->where(function ($q) use ($states, $now) {
                foreach ($states as $state) {
                    match ($state) {
                        'live' => $q->orWhere(fn ($l) => $l->where('is_active', true)
                            ->where(fn ($s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                            ->where(fn ($e) => $e->whereNull('ends_at')->orWhere('ends_at', '>=', $now))),
                        'scheduled' => $q->orWhere(fn ($s) => $s->where('is_active', true)->where('starts_at', '>', $now)),
                        'expired' => $q->orWhere(fn ($e) => $e->whereNotNull('ends_at')->where('ends_at', '<', $now)),
                        'inactive' => $q->orWhere('is_active', false),
                    };
                }
            });
        }

        ListSearch::apply($query, $params->search(), [
            'refs' => ['code'],
            'names' => ['title_ar', 'title_en'],
        ]);

        $codes = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($codes, sort: $sort);
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
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = $promoCode->usages()->getQuery()->with(['user', 'order']);

        $this->whereBetweenDates($query, 'promo_code_usages.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'id' => false,
            'relations' => [
                'user' => ['names' => ['name'], 'phones' => ['phone']],
                'order' => ['refs' => ['order_number']],
            ],
        ]);

        $usages = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($usages, sort: $sort);
    }
}
