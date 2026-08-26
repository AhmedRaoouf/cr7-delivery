<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\CreateComplaintAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreComplaintRequest;
use App\Models\Complaint;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — complaints (customer side): scoped to the caller (doc 12 §5). */
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
        $complaint = $action->execute($request->user(), $request->validated());

        return ApiResponse::success($complaint, 'تم إرسال الشكوى بنجاح', status: 201);
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
            'attachments' => ['nullable', 'array'],
        ]);

        $message = $complaint->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_role' => 'customer',
            'visibility' => 'all',
            'message' => $validated['message'],
            'attachments' => $validated['attachments'] ?? null,
        ]);

        return ApiResponse::success($message, 'تم إرسال الرسالة', status: 201);
    }

    private function ensureOwned(Request $request, Complaint $complaint): void
    {
        abort_unless($complaint->user_id === $request->user()->id, 404);
    }
}
