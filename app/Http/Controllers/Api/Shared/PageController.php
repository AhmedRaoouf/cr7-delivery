<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\StaticPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /pages/{slug}: terms · privacy · about, no auth. */
class PageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = StaticPage::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return ApiResponse::success($page);
    }
}
