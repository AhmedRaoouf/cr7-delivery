<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromoCodeRequest;
use App\Http\Requests\Admin\UpdatePromoCodeRequest;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use App\Support\Media\ThumbUrl;
use App\Support\Promo\PromoCodeNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        // doc 96 — «the codes for this place»: the ones naming it, and with `all` the
        // ones that cover every place too.
        if (($placeIds = $params->ids('restaurant_id')) !== []) {
            $query->where(function ($q) use ($placeIds, $params) {
                foreach ($placeIds as $id) {
                    $q->orWhereJsonContains('restrict_restaurant_ids', $id)
                        ->orWhereJsonContains('restrict_restaurant_ids', (string) $id);
                }

                if ($params->bool('include_all_places') === true) {
                    $q->orWhereNull('restrict_restaurant_ids')->orWhereJsonLength('restrict_restaurant_ids', 0);
                }
            });
        }

        ListSearch::apply($query, $params->search(), [
            'refs' => ['code'],
            'names' => ['title_ar', 'title_en'],
        ]);

        $codes = $sort->apply($query, [])->paginate($params->perPage());
        self::withPlaces($codes->getCollection());

        return $this->paginated($codes, sort: $sort);
    }

    public function show(PromoCode $promoCode): JsonResponse
    {
        return ApiResponse::success(self::withPlaces(collect([$promoCode]))->first());
    }

    /**
     * doc 96 — `GET /admin/promo-codes/suggest?restaurant_ids=…&type=…&value=…`: the code
     * and titles the form fills in as places are picked. Nothing is reserved; the same
     * rule runs again on save when the code is left empty.
     */
    public function suggest(Request $request): JsonResponse
    {
        // A dashboard query string carries a list as `1,2,3`.
        if (is_string($ids = $request->query('restaurant_ids'))) {
            $request->merge(['restaurant_ids' => array_values(array_filter(explode(',', $ids), 'strlen'))]);
        }

        $validated = $request->validate([
            'restaurant_ids' => ['nullable', 'array', 'max:200'],
            'restaurant_ids.*' => ['integer'],
            'type' => ['nullable', 'in:fixed,percent,free_delivery'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'ignore_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::success(PromoCodeNamer::suggest(
            array_map('intval', $validated['restaurant_ids'] ?? []),
            $validated['type'] ?? 'percent',
            (float) ($validated['value'] ?? 0),
            isset($validated['ignore_id']) ? (int) $validated['ignore_id'] : null,
        ));
    }

    public function store(StorePromoCodeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $named = PromoCodeNamer::suggest(
            $data['restrict_restaurant_ids'] ?? [],
            $data['type'],
            (float) $data['value'],
        );

        foreach (['code', 'title_ar', 'title_en'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = $named[$field];
            }
        }

        $promoCode = PromoCode::create($data);

        return ApiResponse::success(self::withPlaces(collect([$promoCode]))->first(), 'تم إنشاء كود الخصم بنجاح', status: 201);
    }

    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode): JsonResponse
    {
        $promoCode->update($request->validated());

        return ApiResponse::success(self::withPlaces(collect([$promoCode->fresh()]))->first(), 'تم تحديث كود الخصم بنجاح');
    }

    /**
     * doc 96 — each code carries the places it is limited to, picture and name, so a
     * list and a form show «TAKKA20 · تكة» rather than ids. One query for the page.
     *
     * @param  Collection<int, PromoCode>  $codes
     * @return Collection<int, PromoCode>
     */
    private static function withPlaces(Collection $codes): Collection
    {
        $ids = $codes->flatMap(fn (PromoCode $code) => $code->restaurantIds())->unique()->values()->all();
        $places = $ids === []
            ? collect()
            : Restaurant::query()->whereIn('id', $ids)->get(['id', 'name_ar', 'name_en', 'logo', 'is_active'])->keyBy('id');

        return $codes->each(fn (PromoCode $code) => $code->setAttribute(
            'restaurants',
            collect($code->restaurantIds())
                ->map(fn (int $id) => $places->get($id))
                ->filter()
                ->map(fn (Restaurant $r) => [
                    'id' => $r->id,
                    'name' => $r->name_ar,
                    'name_en' => $r->name_en,
                    'logo' => $r->logo,
                    'logo_thumb' => ThumbUrl::for($r->logo),
                    'is_active' => (bool) $r->is_active,
                ])
                ->values()
                ->all(),
        ));
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
