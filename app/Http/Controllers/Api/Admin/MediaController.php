<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaField;
use App\Enums\MediaUsage;
use App\Enums\MediaVisibility;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\MediaAudience;
use App\Models\MediaTag;
use App\Models\PlaceGroup;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\ArabicSearch;
use App\Support\AuditLogger;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPicker;
use App\Support\Media\MediaReferences;
use App\Support\Media\MediaSearch;
use App\Support\Media\MediaStore;
use App\Support\Media\ShippedAssets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * doc 71 — the Admin's media library: list, upload, edit, remove, the «Available app
 * images» list, and the picker the admin dashboards and the mobile console share.
 *
 * Reads answer to `media.view`, writes to `media.manage` **and** a global scope — the
 * library is one shared pool, so a regional admin edits none of it. The picker is open to
 * every admin: it is how an admin who may only edit products picks a picture for one.
 */
class MediaController extends Controller
{
    use FiltersLists;

    private const SORTS = ['created_at', 'name', 'bytes', 'source', 'visibility'];

    /** GET /admin/media */
    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $query = MediaAsset::query()->with(['tags', 'audiences']);

        $state = $params->enum('state', ['active', 'removed', 'all']);
        $state = $state[0] ?? 'active';
        $query = match ($state) {
            'removed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };

        MediaSearch::apply($query, $params->search());

        if ($usage = $params->enum('usage', MediaUsage::values())) {
            $query->where(fn ($q) => collect($usage)->each(fn ($u) => $q->orWhereJsonContains('usage_types', $u)));
        }

        if ($source = $params->enum('source', [MediaAsset::SOURCE_UPLOADED, MediaAsset::SOURCE_SHIPPED, MediaAsset::SOURCE_SYSTEM])) {
            $query->whereIn('source', $source);
        }

        if ($visibility = $params->enum('visibility', array_map(fn ($v) => $v->value, MediaVisibility::cases()), ['shared' => 'global'])) {
            $query->whereIn('visibility', $visibility);
        }

        if ($tags = $params->list('tag')) {
            $query->whereHas('tags', fn ($t) => $t->whereIn('slug', $tags)->orWhereIn('media_tags.id', array_filter($tags, 'is_numeric')));
        }

        foreach ([
            'place_id' => MediaAudience::PLACE,
            'group_id' => MediaAudience::GROUP,
            'store_category_id' => MediaAudience::STORE_CATEGORY,
        ] as $key => $type) {
            if ($ids = $params->ids($key)) {
                $query->whereHas('audiences', fn ($a) => $a->where('audience_type', $type)->whereIn('audience_id', $ids));
            }
        }

        $this->whereBetweenDates($query, 'media_assets.created_at', $params->dates());

        $counts = MediaReferences::counts();

        if (($unused = $params->bool('unused')) !== null) {
            $usedIds = MediaAsset::withTrashed()->get(['id', 'disk', 'path'])
                ->filter(fn (MediaAsset $a) => ($counts[$a->url] ?? 0) > 0)->pluck('id')->all();

            $unused ? $query->whereNotIn('media_assets.id', $usedIds) : $query->whereIn('media_assets.id', $usedIds);
        }

        $sort = $params->sort(self::SORTS, '-created_at');
        $page = $sort->apply($query, ['name' => 'media_assets.name_ar', 'created_at' => 'media_assets.created_at', 'bytes' => 'media_assets.bytes', 'source' => 'media_assets.source', 'visibility' => 'media_assets.visibility'])
            ->paginate($params->perPage(24));

        $names = $this->audienceNames($page->getCollection());

        return $this->paginated($page, $page->getCollection()->map(
            fn (MediaAsset $a) => (new MediaAssetResource($a, $counts, $names))->resolve($request)
        )->all(), sort: $sort);
    }

    /** GET /admin/media/{id} — removed assets included, so a removed one can be inspected and restored. */
    public function show(Request $request, int $media): JsonResponse
    {
        $asset = MediaAsset::withTrashed()->with(['tags', 'audiences'])->findOrFail($media);

        return ApiResponse::success(new MediaAssetResource($asset, MediaReferences::counts(), $this->audienceNames(collect([$asset]))));
    }

    /** POST /admin/media — upload one reusable picture. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            ...$this->metaRules(),
        ]);

        $asset = MediaStore::create($request->file('file'), $data, $request->user());
        AuditLogger::record($request->user(), 'media.create', $asset, ['source' => 'uploaded']);

        return ApiResponse::success(
            new MediaAssetResource($asset->load(['tags', 'audiences']), [], $this->audienceNames(collect([$asset]))),
            'تمت إضافة الصورة إلى المكتبة',
            status: 201,
        );
    }

    /** PATCH /admin/media/{id} — names, usage types, tags, visibility and its audiences. */
    public function update(Request $request, int $media): JsonResponse
    {
        $asset = MediaAsset::withTrashed()->findOrFail($media);
        $data = $request->validate($this->metaRules(partial: true));

        $before = $asset->only(['name_ar', 'name_en', 'usage_types', 'visibility']);
        MediaStore::update($asset, $data);
        AuditLogger::record($request->user(), 'media.update', $asset, $data, $before);

        $asset->load(['tags', 'audiences']);

        return ApiResponse::success(
            new MediaAssetResource($asset, MediaReferences::counts(), $this->audienceNames(collect([$asset]))),
            'تم تحديث الصورة',
        );
    }

    /**
     * DELETE /admin/media/{id} — **remove from the library** (the file and every field that
     * shows it are untouched), or with `?purge=1` **delete the physical file**, which is
     * refused while any product, category, place or banner still shows it.
     */
    public function destroy(Request $request, int $media): JsonResponse
    {
        $asset = MediaAsset::withTrashed()->findOrFail($media);

        if ($asset->is_protected) {
            throw new ApiException('media_protected', 'هذه صورة نظام محمية ولا يمكن حذفها', 409);
        }

        if (! $request->boolean('purge')) {
            $asset->delete();
            AuditLogger::record($request->user(), 'media.remove', $asset);

            return ApiResponse::success(message: 'تمت إزالة الصورة من المكتبة — الاستخدامات الحالية لم تتأثر');
        }

        $usage = MediaReferences::breakdown($asset->url);

        if ($usage !== []) {
            throw new ApiException(
                'media_in_use',
                'لا يمكن حذف الملف لأن صورة المكتبة ما زالت مستخدمة',
                409,
                meta: ['usage' => $usage],
            );
        }

        // Shipped pictures are part of the release: only the library row goes, never a file
        // that lives in `public/`.
        if ($asset->disk !== 'web') {
            Storage::disk($asset->disk)->delete($asset->path);
        }

        AuditLogger::record($request->user(), 'media.purge', $asset);
        $asset->forceDelete();

        return ApiResponse::success(message: 'تم حذف الصورة نهائيًا');
    }

    /** POST /admin/media/{id}/restore */
    public function restore(Request $request, int $media): JsonResponse
    {
        $asset = MediaAsset::onlyTrashed()->findOrFail($media);
        $asset->restore();
        AuditLogger::record($request->user(), 'media.restore', $asset);

        return ApiResponse::success(new MediaAssetResource($asset->load(['tags', 'audiences'])), 'تمت استعادة الصورة إلى المكتبة');
    }

    /** GET /admin/media/available — shipped pictures not in the library. */
    public function available(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $items = ShippedAssets::available();

        if ($sources = $params->enum('source', ['backend', 'app', 'dashboard'])) {
            $items = $items->filter(fn ($i) => in_array($i['source'], $sources, true));
        }

        if ($usage = $params->enum('usage', MediaUsage::values())) {
            $items = $items->filter(fn ($i) => array_intersect($usage, $i['usage_types']) !== []);
        }

        if ($folder = $params->string('folder')) {
            $items = $items->filter(fn ($i) => $i['folder'] === $folder);
        }

        if ($tokens = ArabicSearch::tokens($params->search())) {
            $items = $items->filter(function ($i) use ($tokens) {
                $hay = ArabicSearch::normalize($i['key'].' '.$i['name'].' '.implode(' ', $i['tags']).' '.implode(' ', $i['usage_types']).' '.$i['source']);

                return collect($tokens)->every(fn ($t) => str_contains($hay, $t));
            });
        }

        $perPage = $params->perPage(30);
        $pageNo = max(1, (int) $request->input('page', 1));
        $slice = $items->values();
        $page = new LengthAwarePaginator($slice->forPage($pageNo, $perPage)->values(), $slice->count(), $perPage, $pageNo);

        $rows = $page->getCollection()->map(function (array $i) {
            [$w, $h] = ShippedAssets::probe(public_path($i['key']));

            return $i + ['width' => $w, 'height' => $h];
        })->all();

        return $this->paginated($page, $rows, [
            'folders' => ShippedAssets::available()->pluck('folder')->unique()->sort()->values()->all(),
        ]);
    }

    /** POST /admin/media/available — add chosen shipped pictures to the library. */
    public function addAvailable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:100'],
            'keys.*' => ['string', 'max:500'],
            'visibility' => ['sometimes', Rule::in(array_map(fn ($v) => $v->value, MediaVisibility::cases()))],
            'usage_types' => ['sometimes', 'array', 'min:1'],
            'usage_types.*' => [Rule::in(MediaUsage::values())],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $assets = ShippedAssets::add($data['keys'], $request->user(), array_intersect_key($data, array_flip(['visibility', 'usage_types', 'tags'])));

        foreach ($assets as $asset) {
            AuditLogger::record($request->user(), 'media.add_shipped', $asset, ['source_key' => $asset->source_key]);
        }

        return ApiResponse::success(
            collect($assets)->map(fn (MediaAsset $a) => (new MediaAssetResource($a->load(['tags', 'audiences'])))->resolve($request))->all(),
            'تمت إضافة الصور إلى المكتبة',
            status: 201,
        );
    }

    /** GET /admin/media/meta — everything a filter bar or an edit form needs, in one call. */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'usage_types' => MediaUsage::values(),
            'visibilities' => array_map(fn ($v) => $v->value, MediaVisibility::cases()),
            'sources' => [MediaAsset::SOURCE_UPLOADED, MediaAsset::SOURCE_SHIPPED, MediaAsset::SOURCE_SYSTEM],
            'audience_types' => [MediaAudience::PLACE, MediaAudience::GROUP, MediaAudience::STORE_CATEGORY],
            'fields' => collect(MediaField::cases())->map(fn (MediaField $f) => ['field' => $f->value, 'usage' => $f->usage()->value])->all(),
            'tags' => MediaTag::query()->withCount('assets')->orderBy('slug')->get(['id', 'slug', 'name_ar', 'name_en', 'is_system']),
            'groups' => PlaceGroup::query()->withCount('restaurants')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'store_categories' => StoreCategory::query()->orderBy('sort_order')->get(['id', 'slug', 'name_ar', 'name_en']),
            'placeholder_url' => MediaLibrary::placeholderUrl(),
            'available_count' => ShippedAssets::available()->count(),
        ]);
    }

    /** GET /admin/media/picker — the picker for one field, optionally for one place. */
    public function picker(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(MediaField::values())],
            'restaurant_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $place = null;

        if (! empty($data['restaurant_id'])) {
            // Out of the admin's scope is a 404, never a 403 (doc 12 §10).
            $place = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())->findOrFail($data['restaurant_id']);
        }

        $result = MediaPicker::list($request->user(), MediaField::from($data['field']), $place, [
            'q' => $this->listParams($request)->search(),
            'tag' => $this->listParams($request)->string('tag'),
            'per_page' => $this->clampedPerPage($request, 24),
            'page' => (int) $request->input('page', 1),
        ]);

        return $this->paginated($result['page'], $result['items']);
    }

    /** @return array<string, list<mixed>> */
    private function metaRules(bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'name_ar' => [...($partial ? ['sometimes', 'required'] : ['nullable']), 'string', 'max:160'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:160'],
            'usage_types' => [...($partial ? ['sometimes'] : ['nullable']), 'array', 'min:1'],
            'usage_types.*' => [Rule::in(MediaUsage::values())],
            'visibility' => [...$sometimes, 'nullable', Rule::in(array_map(fn ($v) => $v->value, MediaVisibility::cases()))],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
            'audiences' => ['sometimes', 'array', 'max:500'],
            'audiences.*.type' => ['required_with:audiences', Rule::in([MediaAudience::PLACE, MediaAudience::GROUP, MediaAudience::STORE_CATEGORY])],
            'audiences.*.id' => ['required_with:audiences', 'integer', 'min:1'],
        ];
    }

    /**
     * «type:id» → name, for every audience on the given assets — three queries for the page.
     *
     * @param  \Illuminate\Support\Collection<int, MediaAsset>  $assets
     * @return array<string, string>
     */
    private function audienceNames($assets): array
    {
        $assets->each(fn (MediaAsset $a) => $a->relationLoaded('audiences') || $a->load('audiences'));

        $rows = $assets->flatMap(fn (MediaAsset $a) => $a->audiences)->groupBy('audience_type');
        $names = [];

        $lookups = [
            MediaAudience::PLACE => Restaurant::class,
            MediaAudience::GROUP => PlaceGroup::class,
            MediaAudience::STORE_CATEGORY => StoreCategory::class,
        ];

        foreach ($lookups as $type => $model) {
            $ids = ($rows[$type] ?? collect())->pluck('audience_id')->unique()->all();

            if ($ids === []) {
                continue;
            }

            $query = $type === MediaAudience::PLACE ? $model::query() : $model::query();

            foreach ($query->whereIn('id', $ids)->get(['id', 'name_ar']) as $row) {
                $names[$type.':'.$row->id] = $row->name_ar;
            }
        }

        return $names;
    }
}
