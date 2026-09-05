<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 / doc 24 §4 — the platform side of the two-sided complaints box. */
class ComplaintController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::query()
            ->with(['user', 'restaurant', 'assignedTo'])
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->when($request->string('filter.category')->isNotEmpty(), fn ($q) => $q->where('category', $request->string('filter.category')))
            ->when($request->string('filter.priority')->isNotEmpty(), fn ($q) => $q->where('priority', $request->string('filter.priority')))
            // `ADM-53`'s «متأخرة» toggle. Overdue is the same question `SlaChip` asks per
            // row — past `due_at` and not yet resolved — and it was answered client-side
            // only, so the filter returned the full list and the count above it was wrong.
            ->when($this->filterBool($request, 'overdue') === true, fn ($q) => $q
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNull('resolved_at'))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($complaints->items(), meta: [
            'current_page' => $complaints->currentPage(),
            'per_page' => $complaints->perPage(),
            'total' => $complaints->total(),
            'last_page' => $complaints->lastPage(),
        ]);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        return ApiResponse::success($complaint->load(['user', 'restaurant', 'assignedTo', 'refund', 'messages.sender', 'order']));
    }

    public function addMessage(Request $request, Complaint $complaint): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array'],
            'visibility' => ['nullable', 'in:all,internal'],
        ]);

        $message = $complaint->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_role' => 'admin',
            'visibility' => $validated['visibility'] ?? 'all',
            'message' => $validated['message'],
            'attachments' => $validated['attachments'] ?? null,
        ]);

        if (! $complaint->first_response_at) {
            $complaint->update(['first_response_at' => now()]);
        }

        return ApiResponse::success($message, 'تم إرسال الرد', status: 201);
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,in_progress,resolved,rejected'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'refund_id' => ['nullable', 'exists:refunds,id'],
            'visible_to_restaurant' => ['nullable', 'boolean'],
            'resolution_outcome' => ['nullable', 'in:refund,partial_refund,redelivery,warning_restaurant,warning_driver,no_action,rejected'],
        ]);

        if (($validated['status'] ?? null) === 'resolved') {
            $validated['resolved_at'] = now();
        }

        $complaint->update($validated);
        AuditLogger::record($request->user(), 'complaints.update', $complaint, $validated);

        return ApiResponse::success($complaint->fresh(), 'تم تحديث الشكوى بنجاح');
    }

    public function assign(Request $request, Complaint $complaint): JsonResponse
    {
        $validated = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);
        $complaint->update($validated);
        AuditLogger::record($request->user(), 'complaints.assign', $complaint, $validated);

        return ApiResponse::success($complaint->fresh(), 'تم إسناد الشكوى');
    }

    public function escalate(Request $request, Complaint $complaint): JsonResponse
    {
        $validated = $request->validate(['priority' => ['required', 'in:low,normal,high']]);

        $hours = (int) (Setting::query()->where('key', "support.resolution_hours.{$validated['priority']}")->value('value') ?? 24);

        $complaint->update(['priority' => $validated['priority'], 'due_at' => now()->addHours($hours)]);
        AuditLogger::record($request->user(), 'complaints.escalate', $complaint, $validated);

        return ApiResponse::success($complaint->fresh(), 'تم تصعيد الشكوى');
    }

    public function sla(): JsonResponse
    {
        $now = now();

        return ApiResponse::success([
            'within' => Complaint::query()->whereNotIn('status', ['resolved', 'rejected'])->where('due_at', '>', $now->clone()->addHours(2))->count(),
            'due_soon' => Complaint::query()->whereNotIn('status', ['resolved', 'rejected'])->whereBetween('due_at', [$now, $now->clone()->addHours(2)])->count(),
            'breached' => Complaint::query()->whereNotIn('status', ['resolved', 'rejected'])->where('due_at', '<', $now)->count(),
        ]);
    }
}
