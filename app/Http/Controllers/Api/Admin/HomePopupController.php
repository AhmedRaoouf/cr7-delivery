<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHomePopupRequest;
use App\Http\Requests\Admin\UpdateHomePopupRequest;
use App\Http\Resources\Shared\HomePopupResource;
use App\Models\HomePopup;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The home screen's popup card, editable — «العرض المنبثق».
 *
 * Operator, 2026-09-08: «الأدمين بيحدد شكله من الداشبورد، فاديله الأكسيس إن هو يعمل أي
 * حاجة فيه، يحط زرار، يحط زرارين، يظبط كل حاجة، الصورة، الكلام على الصورة… وتخليه أصلا
 * إن هو يظهر ولا ما يظهرش». So every part of the card is a column and none of it is a
 * deploy: the artwork, both languages of every string, how many buttons there are, where
 * they go, whether the card runs at all, and in what order it runs against the others.
 *
 * Multipart, like `StoreCategoryController`, because the artwork is most of the card.
 */
class HomePopupController extends Controller
{
    use HandlesUploads;

    public function index(Request $request): JsonResponse
    {
        $popups = HomePopup::query()
            ->with('storeCategory:id,slug')
            ->when($request->boolean('active_only'), fn ($q) => $q->live())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(HomePopupResource::collection($popups));
    }

    public function show(HomePopup $homePopup): JsonResponse
    {
        return ApiResponse::success(new HomePopupResource($homePopup->load('storeCategory:id,slug')));
    }

    public function store(StoreHomePopupRequest $request): JsonResponse
    {
        $data = $this->withoutStaleDestinations($request->validated());

        if ($request->hasFile('image')) {
            $data['image'] = FileUploader::storePublic($request->file('image'), 'home-popups');
        }

        $popup = HomePopup::create($data);

        AuditLogger::record($request->user(), 'home_popups.create', $popup);

        // `fresh()`, not the instance `create()` returned: `frequency`,
        // `secondary_action` and `is_active` are column defaults, so a card saved
        // without them comes back as three nulls and the dashboard draws a form with
        // three empty selects over a row that is perfectly well set.
        return ApiResponse::success(
            new HomePopupResource($popup->fresh()->load('storeCategory:id,slug')),
            'تم إنشاء العرض المنبثق بنجاح',
            status: 201,
        );
    }

    public function update(UpdateHomePopupRequest $request, HomePopup $homePopup): JsonResponse
    {
        $data = $this->withoutStaleDestinations($request->validated(), $homePopup);

        // A replaced picture takes the old file with it. Left in place, a season of
        // offers is a season of orphans on a disk nobody sweeps.
        //
        // `withPublicUpload` rather than a hand-rolled `hasFile`: it is the concern the
        // rest of the admin surface uses, and it carries the one thing this method was
        // missing — `remove_image`. A multipart body cannot send a JSON null, so an
        // absent file means «leave it alone» and only the sibling boolean means «clear
        // it». Without that flag the dashboard could replace the artwork and never take
        // it off.
        $data = $this->withPublicUpload($data, $request, 'image', 'home-popups', $homePopup);

        $homePopup->update($data);

        AuditLogger::record($request->user(), 'home_popups.update', $homePopup);

        return ApiResponse::success(
            new HomePopupResource($homePopup->fresh()->load('storeCategory:id,slug')),
            'تم تحديث العرض المنبثق بنجاح',
        );
    }

    /**
     * Soft-deleted, and the artwork is kept.
     *
     * A seasonal card comes back next Ramadan; deleting its picture would mean retyping
     * the whole thing, and the file is a few hundred kilobytes.
     */
    public function destroy(Request $request, HomePopup $homePopup): JsonResponse
    {
        $homePopup->delete();

        AuditLogger::record($request->user(), 'home_popups.delete', $homePopup);

        return ApiResponse::success(message: 'تم حذف العرض المنبثق بنجاح');
    }

    /**
     * «يظهر كذا واحد يعني ورا بعض» — the queue's order, in one request, so dragging
     * three cards does not fire three PATCHes and leave a half-applied order behind.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:home_popups,id'],
        ]);

        foreach ($validated['ids'] as $index => $id) {
            HomePopup::query()->whereKey($id)->update(['sort_order' => $index]);
        }

        // Logged against the first card in the new order rather than not at all —
        // `AuditLogger` requires a subject, and the order of what the customer is shown
        // on opening the app is not a change that may happen untraceably.
        $first = HomePopup::query()->find($validated['ids'][0]);

        if ($first !== null) {
            AuditLogger::record($request->user(), 'home_popups.reorder', $first, ['ids' => $validated['ids']]);
        }

        return ApiResponse::success(message: 'تم حفظ الترتيب');
    }

    /**
     * One destination at a time.
     *
     * The five target columns are independent, so a card edited from «منتج» to «كود خصم»
     * would keep its `product_id` — and the app's resolver looks at the ids before it
     * looks at `link_type` (`BannerDestination::resolve`), so that stale id is what the
     * button would open. The columns the current `link_type` does not use are cleared.
     */
    private function withoutStaleDestinations(array $data, ?HomePopup $existing = null): array
    {
        $linkType = $data['link_type']
            ?? $existing?->link_type?->value
            ?? 'none';

        $keep = match ($linkType) {
            'restaurant' => 'restaurant_id',
            'product' => 'product_id',
            'category' => 'store_category_id',
            'promo' => 'code',
            'url' => 'url',
            default => null,
        };

        foreach (['restaurant_id', 'product_id', 'store_category_id', 'code', 'url'] as $field) {
            if ($field !== $keep) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
