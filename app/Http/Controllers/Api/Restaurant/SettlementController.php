<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET /restaurant/settlements · /{id}. */
class SettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $settlements = Settlement::query()
            ->where('party_type', 'restaurant')
            ->where('party_id', $restaurant->id)
            ->latest('period_end')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($settlements->items(), meta: [
            'current_page' => $settlements->currentPage(),
            'per_page' => $settlements->perPage(),
            'total' => $settlements->total(),
            'last_page' => $settlements->lastPage(),
        ]);
    }

    public function show(Request $request, Settlement $settlement): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        abort_unless($settlement->party_type->value === 'restaurant' && $settlement->party_id === $restaurant->id, 404);

        return ApiResponse::success($settlement->load('items.order'));
    }
}
