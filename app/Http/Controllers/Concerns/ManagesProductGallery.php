<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\Media\ProductGallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 72 — the per-picture gallery endpoints, shared by the restaurant and admin
 * controllers. The caller has already established that the actor may touch this product;
 * everything else — the lock, the version check, the cap, the file cleanup — is
 * {@see ProductGallery}.
 *
 * Every action answers the whole product (`gallery`, `gallery_items`, `gallery_version`), so
 * a client re-renders from one response and holds a fresh token for its next write.
 */
trait ManagesProductGallery
{
    private function appendToGallery(Request $request, Product $product, ?Restaurant $place): JsonResponse
    {
        $validated = $request->validate([
            'gallery' => ['required_without:gallery_media', 'array', 'min:1', 'max:'.Product::GALLERY_MAX],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'gallery_media' => ['required_without:gallery', 'array', 'min:1', 'max:'.Product::GALLERY_MAX],
            'gallery_media.*' => ['string', 'max:2048'],
            'expected_version' => ['nullable', 'string', 'max:64'],
        ]);

        $refs = array_values(array_filter((array) ($validated['gallery_media'] ?? []), fn ($ref) => is_string($ref) && $ref !== ''));
        $files = $request->hasFile('gallery') ? array_values((array) $request->file('gallery')) : [];

        $product = ProductGallery::append($product, $request->user(), $place, $files, $refs, $validated['expected_version'] ?? null);

        return $this->galleryResponse($product, 'تمت إضافة الصور إلى معرض المنتج');
    }

    private function removeFromGallery(Request $request, Product $product, string $ref): JsonResponse
    {
        $validated = $request->validate(['expected_version' => ['nullable', 'string', 'max:64']]);

        $product = ProductGallery::remove($product, $ref, $validated['expected_version'] ?? null);

        return $this->galleryResponse($product, 'تم حذف الصورة من معرض المنتج');
    }

    private function reorderGallery(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'max:'.Product::GALLERY_MAX],
            'order.*' => ['string', 'max:64'],
            'expected_version' => ['nullable', 'string', 'max:64'],
        ]);

        $product = ProductGallery::reorder($product, array_values($validated['order']), $validated['expected_version'] ?? null);

        return $this->galleryResponse($product, 'تم ترتيب صور المعرض');
    }

    private function galleryResponse(Product $product, string $message): JsonResponse
    {
        return ApiResponse::success($product->load('optionGroups.options')->withGalleryMeta(), $message);
    }
}
