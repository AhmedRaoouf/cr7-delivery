<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintUserRole;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Setting;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\ComplaintNotifier;
use App\Support\Lists\ListSearch;
use App\Support\UploadedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** doc 08 §7 / doc 24 §4 — the platform side of the two-sided complaints box. */
class ComplaintController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'due_at', 'updated_at', 'priority'], '-created_at');

        $query = AdminScope::applyToComplaints(Complaint::query(), $request->user())
            ->with(['user', 'restaurant', 'assignedTo']);

        $this->whereInList($query, 'complaints.status', $params->enum('status', array_column(ComplaintStatus::cases(), 'value')));
        $this->whereInList($query, 'complaints.category', $params->enum('category', array_column(ComplaintCategory::cases(), 'value')));
        $this->whereInList($query, 'complaints.priority', $params->enum('priority', array_column(ComplaintPriority::cases(), 'value')));
        $this->whereInList($query, 'complaints.user_role', $params->enum('user_role', array_column(ComplaintUserRole::cases(), 'value')));
        $this->whereInList($query, 'complaints.restaurant_id', $params->ids('restaurant_id'));

        if (($assignees = $params->ids('assigned_to', ['none', 'me'])) !== []) {
            $query->where(function ($q) use ($assignees, $request) {
                foreach ($assignees as $assignee) {
                    match ($assignee) {
                        'none' => $q->orWhereNull('complaints.assigned_to'),
                        'me' => $q->orWhere('complaints.assigned_to', $request->user()->id),
                        default => $q->orWhere('complaints.assigned_to', $assignee),
                    };
                }
            });
        }

        $cities = $params->ids('city_id');
        $zones = $params->ids('zone_id');

        if ($cities !== [] || $zones !== []) {
            $query->whereHas('order', function ($order) use ($cities, $zones) {
                $this->whereInList($order, 'orders.city_id', $cities);
                $this->whereInList($order, 'orders.service_zone_id', $zones);
            });
        }

        // `ADM-53`'s «متأخرة» toggle. Overdue is the same question `SlaChip` asks per row —
        // past `due_at` and not yet resolved — and it was answered client-side only, so the
        // filter returned the full list and the count above it was wrong.
        if (($overdue = $params->bool('overdue')) !== null) {
            $late = fn ($q) => $q->whereNotNull('complaints.due_at')
                ->where('complaints.due_at', '<', now())
                ->whereNull('complaints.resolved_at');
            $overdue ? $query->where($late) : $query->whereNot($late);
        }

        $this->whereBetweenDates($query, 'complaints.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['ticket_number'],
            'names' => ['subject'],
            'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        $complaints = $sort->apply($query, [
            // Most urgent first when descending — the stored enum's own order.
            'priority' => fn ($q, string $direction) => $q->orderByRaw("FIELD(complaints.priority, 'low', 'normal', 'high') {$direction}"),
        ])->paginate($params->perPage());

        return $this->paginated($complaints, sort: $sort);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureInScope($request, $complaint);

        return ApiResponse::success($complaint->load(['user', 'restaurant', 'assignedTo', 'refund', 'messages.sender', 'order']));
    }

    public function addMessage(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureInScope($request, $complaint);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'visibility' => ['nullable', 'in:all,internal'],
        ]);

        // Batch 67 — doc 12 §3: an internal note is `complaints.note_internal`, which
        // `operations` does not hold. `complaints.reply` alone could write one.
        abort_if(
            ($validated['visibility'] ?? 'all') === 'internal' && ! $request->user()->can('complaints.note_internal'),
            403,
        );

        $message = $complaint->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_role' => 'admin',
            'visibility' => $validated['visibility'] ?? 'all',
            'message' => $validated['message'],
            'attachments' => UploadedImages::store($request, 'attachments', 'complaints') ?: null,
        ]);

        if (! $complaint->first_response_at) {
            $complaint->update(['first_response_at' => now()]);
        }

        // An `internal` note stops at the operators — that is the whole meaning of the
        // visibility column, and it has to hold in the push as well as on the screen.
        app(ComplaintNotifier::class)->replied($complaint->fresh(['user', 'restaurant.owner']), $message);

        return ApiResponse::success($message, 'تم إرسال الرد', status: 201);
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureInScope($request, $complaint);

        $validated = $request->validate([
            'status' => ['nullable', 'in:open,in_progress,resolved,rejected'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'assigned_to' => ['nullable', self::assignee()],
            'refund_id' => ['nullable', 'exists:refunds,id'],
            'visible_to_restaurant' => ['nullable', 'boolean'],
            'resolution_outcome' => ['nullable', 'in:refund,partial_refund,redelivery,warning_restaurant,warning_driver,no_action,rejected'],
        ]);

        // Batch 67 — showing a ticket to the restaurant it concerns, or hiding it, is
        // `complaints.mirror` (D-53, doc 12 §3), not `complaints.update`.
        abort_if(
            array_key_exists('visible_to_restaurant', $validated) && ! $request->user()->can('complaints.mirror'),
            403,
        );

        if (($validated['status'] ?? null) === 'resolved') {
            $validated['resolved_at'] = now();
        }

        $complaint->update($validated);
        AuditLogger::record($request->user(), 'complaints.update', $complaint, $validated);

        if (($validated['status'] ?? null) === 'resolved') {
            app(ComplaintNotifier::class)->resolved($complaint->fresh(['user']));
        }

        return ApiResponse::success($complaint->fresh(), 'تم تحديث الشكوى بنجاح');
    }

    public function assign(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureInScope($request, $complaint);

        $validated = $request->validate(['assigned_to' => ['required', self::assignee()]]);
        $complaint->update($validated);
        AuditLogger::record($request->user(), 'complaints.assign', $complaint, $validated);

        return ApiResponse::success($complaint->fresh(), 'تم إسناد الشكوى');
    }

    public function escalate(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureInScope($request, $complaint);

        $validated = $request->validate(['priority' => ['required', 'in:low,normal,high']]);

        $hours = (int) (Setting::query()->where('key', "support.resolution_hours.{$validated['priority']}")->value('value') ?? 24);

        $complaint->update(['priority' => $validated['priority'], 'due_at' => now()->addHours($hours)]);
        AuditLogger::record($request->user(), 'complaints.escalate', $complaint, $validated);

        return ApiResponse::success($complaint->fresh(), 'تم تصعيد الشكوى');
    }

    public function sla(Request $request): JsonResponse
    {
        $now = now();
        $open = fn () => AdminScope::applyToComplaints(Complaint::query(), $request->user())->whereNotIn('status', ['resolved', 'rejected']);

        return ApiResponse::success([
            'within' => $open()->where('due_at', '>', $now->clone()->addHours(2))->count(),
            'due_soon' => $open()->whereBetween('due_at', [$now, $now->clone()->addHours(2)])->count(),
            'breached' => $open()->where('due_at', '<', $now)->count(),
        ]);
    }

    /** Batch 67 — D-52: a scoped admin's complaint is in scope or it does not exist. */
    private function ensureInScope(Request $request, Complaint $complaint): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToComplaints(Complaint::query(), $request->user()), $complaint);
    }

    /** Batch 67 — a ticket is assigned to an operator. `exists:users,id` accepted a customer. */
    private static function assignee(): Exists
    {
        return Rule::exists('users', 'id')->where('type', UserType::Admin->value)->whereNull('deleted_at');
    }
}
