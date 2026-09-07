<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\CreateComplaintAction;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreComplaintRequest;
use App\Models\Complaint;
use App\Support\ApiResponse;
use App\Support\ComplaintNotifier;
use App\Support\UploadedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 08 §4 — complaints, for both halves of the one app.
 *
 * The customer files them from `CUS-40`; the captain files them from `DRV-32`, which
 * routes to the same screens. `user_role` on the row and `sender_role` on each message
 * record which one it was, so a captain's ticket is never mistaken for a customer's on
 * `ADM-41`. Every row is scoped to the caller (doc 12 §5).
 */
class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $complaints = $request->user()->complaints()->latest()->paginate($request->integer('per_page', 20));

        return ApiResponse::success($complaints->items(), meta: [
            'current_page' => $complaints->currentPage(),
            'per_page' => $complaints->perPage(),
            'total' => $complaints->total(),
            'last_page' => $complaints->lastPage(),
        ]);
    }

    public function store(StoreComplaintRequest $request, CreateComplaintAction $action): JsonResponse
    {
        $data = $request->validated();
        $data['attachments'] = UploadedImages::store($request, 'attachments', 'complaints');
        $data['user_role'] = $this->role($request);

        $complaint = $action->execute($request->user(), $data);

        // With the thread seeded, the client can open `CUS-42` on the created row
        // instead of re-reading it — so the create answer carries the same shape
        // `show` does.
        return ApiResponse::success($complaint->load('messages'), 'تم إرسال الشكوى بنجاح', status: 201);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureOwned($request, $complaint);

        return ApiResponse::success($complaint->load(['messages' => fn ($q) => $q->where('visibility', 'all')]));
    }

    public function addMessage(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureOwned($request, $complaint);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['image', 'max:5120'],
        ]);

        $message = $complaint->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_role' => $this->role($request),
            'visibility' => 'all',
            'message' => $validated['message'],
            'attachments' => UploadedImages::store($request, 'attachments', 'complaints') ?: null,
        ]);

        app(ComplaintNotifier::class)->replied($complaint->fresh(['user', 'restaurant.owner']), $message);

        return ApiResponse::success($message, 'تم إرسال الرسالة', status: 201);
    }

    private function ensureOwned(Request $request, Complaint $complaint): void
    {
        abort_unless($complaint->user_id === $request->user()->id, 404);
    }

    /** `customer` or `driver` — `type:customer,driver` guarantees it is one of the two. */
    private function role(Request $request): string
    {
        return $request->user()->type === UserType::Driver ? 'driver' : 'customer';
    }
}
