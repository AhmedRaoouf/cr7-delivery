<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DeliveryIssueCategory;
use App\Enums\DeliveryIssueReportedBy;
use App\Enums\DeliveryIssueStatus;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\DeliveryIssue;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET /admin/delivery-issues, POST /{id}/resolve. */
class DeliveryIssueController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = AdminScope::applyToDeliveryIssues(DeliveryIssue::query(), $request->user())
            ->with(['order.restaurant', 'reporter']);

        $this->whereInList($query, 'delivery_issues.status', $params->enum('status', array_column(DeliveryIssueStatus::cases(), 'value')));
        $this->whereInList($query, 'delivery_issues.category', $params->enum('category', array_column(DeliveryIssueCategory::cases(), 'value')));
        $this->whereInList($query, 'delivery_issues.reported_by', $params->enum('reported_by', array_column(DeliveryIssueReportedBy::cases(), 'value')));

        $restaurants = $params->ids('restaurant_id');
        $drivers = $params->ids('driver_id');

        if ($restaurants !== [] || $drivers !== []) {
            $query->whereHas('order', function ($order) use ($restaurants, $drivers) {
                $this->whereInList($order, 'orders.restaurant_id', $restaurants);
                $this->whereInList($order, 'orders.driver_id', $drivers);
            });
        }

        $this->whereBetweenDates($query, 'delivery_issues.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'relations' => ['order' => ['refs' => ['order_number']]],
        ]);

        $issues = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($issues, sort: $sort);
    }

    public function resolve(Request $request, DeliveryIssue $deliveryIssue): JsonResponse
    {
        // Batch 67 — an issue follows its order's scope (D-52).
        AdminScope::abortUnlessVisible(AdminScope::applyToDeliveryIssues(DeliveryIssue::query(), $request->user()), $deliveryIssue);

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
