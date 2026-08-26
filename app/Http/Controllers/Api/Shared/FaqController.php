<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /faqs, no auth. */
class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Faq::query()->orderBy('sort_order')->get());
    }
}
