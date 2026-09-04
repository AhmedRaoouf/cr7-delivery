<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryIssue;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET /admin/delivery-issues, POST /{id}/resolve. */
class DeliveryIssueController extends Controller
{
    use \App\Http\Controllers\Concerns\FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $issues = DeliveryIssue::query()
            ->with(['order.restaurant', 'reporter'])
            ->when($this->filterString($request, 'status') !== '', fn ($q) => $q->where('status', $this->filterString($request, 'status')))
            ->when($this->filterInt($request, 'restaurant_id'), fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('restaurant_id', $id)))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($issues->items(), meta: [
            'current_page' => $issues->currentPage(),
            'per_page' => $issues->perPage(),
            'total' => $issues->total(),
            'last_page' => $issues->lastPage(),
        ]);
    }

    public function resolve(Request $request, DeliveryIssue $deliveryIssue): JsonResponse
    {
        $validated = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);

        $deliveryIssue->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolution_note' => $validated['resolution_note'],
            'resolved_at' => now(),
        ]);

        AuditLogger::record($request->user(), 'orders.resolve_issue', $deliveryIssue, $validated);

        return ApiResponse::success($deliveryIssue->fresh(), 'تم حل المشكلة بنجاح');
    }
}
