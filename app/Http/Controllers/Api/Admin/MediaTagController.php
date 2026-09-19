<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MediaTag;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** doc 71 — the tags an asset can carry. The seeded ones are `is_system` and cannot be deleted. */
class MediaTagController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(MediaTag::query()->withCount('assets')->orderBy('slug')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['nullable', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:media_tags,slug'],
        ]);

        $tag = MediaTag::create([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'slug' => $data['slug'] ?? $this->slugFor($data['name_en'] ?? $data['name_ar']),
        ]);
        AuditLogger::record($request->user(), 'media_tags.create', $tag);

        return ApiResponse::success($tag, 'تمت إضافة الوسم', status: 201);
    }

    public function update(Request $request, MediaTag $tag): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'required', 'string', 'max:80'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $tag->update($data);
        AuditLogger::record($request->user(), 'media_tags.update', $tag, $data);

        return ApiResponse::success($tag, 'تم تحديث الوسم');
    }

    public function destroy(Request $request, MediaTag $tag): JsonResponse
    {
        if ($tag->is_system) {
            throw new ApiException('media_tag_protected', 'هذا وسم نظام ولا يمكن حذفه', 409);
        }

        // Only the links go — the pictures stay in the library.
        AuditLogger::record($request->user(), 'media_tags.delete', $tag);
        $tag->delete();

        return ApiResponse::success(message: 'تم حذف الوسم');
    }

    private function slugFor(string $source): string
    {
        $base = Str::slug($source) ?: 'tag';
        $slug = $base;

        for ($i = 2; MediaTag::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
