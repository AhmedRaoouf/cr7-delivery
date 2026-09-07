<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStoreCategoryRequest;
use App\Http\Requests\Admin\UpdateStoreCategoryRequest;
use App\Http\Resources\Shared\StoreCategoryResource;
use App\Models\StoreCategory;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The home screen's top level, editable.
 *
 * Operator, 2026-09-07: «الأدمين ممكن يضيف اللي هو عايزه… ممكن يضيف كمان واحدة مثلاً
 * فواكه بدل خضروات، بنزينات، أي حاجة من دي… لما تتضاف من الداشبورد، تقوم جاية هنا في
 * التطبيق». So this is a table with a CRUD screen, not a constant with a deploy.
 *
 * Multipart, because the card art is the point: a category with no image renders as a
 * flat tile and the operator will not be able to tell it apart from the next one.
 */
class StoreCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = StoreCategory::query()
            ->withCount(['restaurants' => fn ($q) => $q->where('is_active', true)])
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(StoreCategoryResource::collection($categories));
    }

    public function show(StoreCategory $storeCategory): JsonResponse
    {
        return ApiResponse::success(new StoreCategoryResource($storeCategory));
    }

    public function store(StoreStoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name_en']);

        foreach (['image', 'icon'] as $field) {
            $data[$field] = $request->hasFile($field)
                ? FileUploader::storePublic($request->file($field), 'store-categories')
                : ($data[$field] ?? null);
        }

        $category = StoreCategory::create($data);

        AuditLogger::record($request->user(), 'store_categories.create', $category);

        return ApiResponse::success(new StoreCategoryResource($category), 'تم إنشاء التصنيف بنجاح', status: 201);
    }

    public function update(UpdateStoreCategoryRequest $request, StoreCategory $storeCategory): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $storeCategory->id);
        }

        // A replaced picture takes the old file with it. Left in place, a year of edits
        // is a year of orphans on a disk nobody sweeps.
        foreach (['image', 'icon'] as $field) {
            if ($request->hasFile($field)) {
                FileUploader::deletePublic($storeCategory->{$field});
                $data[$field] = FileUploader::storePublic($request->file($field), 'store-categories');
            } else {
                unset($data[$field]);
            }
        }

        $storeCategory->update($data);

        AuditLogger::record($request->user(), 'store_categories.update', $storeCategory);

        return ApiResponse::success(new StoreCategoryResource($storeCategory->fresh()), 'تم تحديث التصنيف بنجاح');
    }

    /**
     * Soft-deleted, and the shops that pointed at it are set loose rather than deleted.
     *
     * `store_category_id` is `nullOnDelete`, but a soft delete never fires that — the row
     * stays and every restaurant keeps pointing at a category the app no longer lists,
     * which is how a restaurant disappears from the home screen without anybody touching
     * it. Cleared here, explicitly.
     */
    public function destroy(Request $request, StoreCategory $storeCategory): JsonResponse
    {
        $storeCategory->restaurants()->update(['store_category_id' => null]);
        $storeCategory->delete();

        AuditLogger::record($request->user(), 'store_categories.delete', $storeCategory);

        return ApiResponse::success(message: 'تم حذف التصنيف بنجاح');
    }

    /** «رتّب» — one request for the whole strip, so a drag does not fire five PATCHes. */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:store_categories,id'],
        ]);

        foreach ($validated['ids'] as $index => $id) {
            StoreCategory::query()->whereKey($id)->update(['sort_order' => $index]);
        }

        // Logged against the first row in the new order rather than not at all —
        // `AuditLogger` requires a subject, and a reorder with no trace is the one
        // change to the home screen nobody could explain afterwards.
        $first = StoreCategory::query()->find($validated['ids'][0]);

        if ($first !== null) {
            AuditLogger::record($request->user(), 'store_categories.reorder', $first, ['ids' => $validated['ids']]);
        }

        return ApiResponse::success(message: 'تم حفظ الترتيب');
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        // `Str::slug` drops Arabic entirely — «صيدليات» becomes the empty string, and the
        // second category the operator names in Arabic collides with the first.
        $base = Str::slug($source) ?: 'category-'.Str::lower(Str::random(6));
        $slug = $base;
        $suffix = 2;

        while (StoreCategory::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
