<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaField;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBannerRequest;
use App\Models\Banner;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Support\ApiResponse;
use App\Support\Media\ThumbUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/** doc 08 §7 — GET|POST|PATCH|DELETE /admin/banners[/{id}]. */
class BannerController extends Controller
{
    use HandlesUploads;

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->withDestinations(Banner::query()->orderBy('sort_order')->get()));
    }

    public function show(Banner $banner): JsonResponse
    {
        return ApiResponse::success($this->withDestinations(collect([$banner]))->first());
    }

    public function store(StoreBannerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image_ar', 'banners', media: MediaField::BannerImage);
        $data = $this->withPublicUpload($data, $request, 'image_en', 'banners', media: MediaField::BannerImage);

        $banner = Banner::create($data);

        return ApiResponse::success($banner, 'تم إنشاء البانر بنجاح', status: 201);
    }

    public function update(StoreBannerRequest $request, Banner $banner): JsonResponse
    {
        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image_ar', 'banners', $banner, MediaField::BannerImage);
        $data = $this->withPublicUpload($data, $request, 'image_en', 'banners', $banner, MediaField::BannerImage);

        $banner->update($data);

        return ApiResponse::success($banner->fresh(), 'تم تحديث البانر بنجاح');
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();

        return ApiResponse::success(message: 'تم حذف البانر بنجاح');
    }

    /**
     * 2026-10-14 — each row names where it lands: `destination: {label, sub, image, missing}`.
     *
     * The list printed `link_value` — «2258», «1» — so a slide pointing at the wrong place
     * looked exactly like one pointing at the right one. Resolved in one query per kind,
     * soft-deleted rows included, so a dish deleted since reads as itself and is flagged.
     *
     * @param  Collection<int, Banner>  $banners
     * @return Collection<int, array<string, mixed>>
     */
    private function withDestinations(Collection $banners): Collection
    {
        $values = fn (string $type) => $banners
            ->filter(fn (Banner $b) => $b->link_type?->value === $type && $b->link_value !== null)
            ->pluck('link_value')->unique()->values();
        $ids = fn (string $type) => $values($type)->filter(fn ($v) => ctype_digit((string) $v))->map(fn ($v) => (int) $v);

        $places = Restaurant::withTrashed()->whereIn('id', $ids('restaurant'))
            ->get(['id', 'name_ar', 'logo', 'deleted_at'])->keyBy('id');
        $products = Product::withTrashed()->with('restaurant:id,name_ar')->whereIn('id', $ids('product'))
            ->get(['id', 'restaurant_id', 'name_ar', 'image', 'deleted_at'])->keyBy('id');
        $promos = PromoCode::query()->whereIn('code', $values('promo'))->get(['code', 'title_ar'])->keyBy('code');
        $categories = StoreCategory::query()->whereIn('slug', $values('category'))->get(['slug', 'name_ar'])->keyBy('slug');

        return $banners->map(function (Banner $banner) use ($places, $products, $promos, $categories): array {
            $value = (string) $banner->link_value;
            $destination = match ($banner->link_type?->value) {
                'restaurant' => ($r = $places->get((int) $value)) === null || $r->trashed()
                    ? ['label' => null, 'sub' => null, 'image' => null, 'missing' => true]
                    : ['label' => $r->name_ar, 'sub' => null, 'image' => ThumbUrl::for($r->logo), 'missing' => false],
                'product' => ($p = $products->get((int) $value)) === null || $p->trashed()
                    ? ['label' => null, 'sub' => null, 'image' => null, 'missing' => true]
                    : ['label' => $p->name_ar, 'sub' => $p->restaurant?->name_ar, 'image' => ThumbUrl::for($p->image), 'missing' => false],
                'promo' => ($c = $promos->get($value)) === null
                    ? ['label' => $value, 'sub' => null, 'image' => null, 'missing' => true]
                    : ['label' => $c->code, 'sub' => $c->title_ar, 'image' => null, 'missing' => false],
                'category' => ($c = $categories->get($value)) === null
                    ? ['label' => $value, 'sub' => null, 'image' => null, 'missing' => true]
                    : ['label' => $c->name_ar, 'sub' => null, 'image' => null, 'missing' => false],
                'url' => ['label' => $value, 'sub' => null, 'image' => null, 'missing' => false],
                default => null,
            };

            return $banner->toArray() + ['destination' => $destination];
        });
    }
}
