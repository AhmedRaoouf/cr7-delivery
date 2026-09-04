<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\GenerateSettlementAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\SettlementExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §7 — GET /admin/settlements, POST /generate, GET /{id}, POST /{id}/pay. */
class SettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = Settlement::query()
            ->when($request->string('filter.party_type')->isNotEmpty(), fn ($q) => $q->where('party_type', $request->string('filter.party_type')))
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest('period_end')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($settlements->items(), meta: [
            'current_page' => $settlements->currentPage(),
            'per_page' => $settlements->perPage(),
            'total' => $settlements->total(),
            'last_page' => $settlements->lastPage(),
        ]);
    }

    public function generate(Request $request, GenerateSettlementAction $action): JsonResponse
    {
        $validated = $request->validate([
            'party_type' => ['required', 'in:restaurant,driver'],
            'party_id' => ['required', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $settlement = $action->execute($request->user(), $validated['party_type'], $validated['party_id'], $validated['period_start'], $validated['period_end']);

        return ApiResponse::success($settlement, 'تم إنشاء كشف التسوية بنجاح', status: 201);
    }

    public function show(Settlement $settlement): JsonResponse
    {
        return ApiResponse::success($settlement->load(['items.order', 'party', 'createdBy']));
    }

    /**
     * `RES-31` / `ADM-33` — the settlement as a workbook.
     *
     * Streamed rather than built and returned: a settlement covering a busy month is
     * thousands of order lines, and holding the sheet in memory first is how a
     * 128 MB shared-hosting limit is hit by a report nobody thought was large.
     */
    public function export(Settlement $settlement): StreamedResponse
    {
        $party = $settlement->party;
        $name = $party?->getAttribute('name_ar') ?? $party?->getAttribute('name') ?? '';

        return SettlementExporter::stream($settlement, (string) $name);
    }

    public function pay(Request $request, Settlement $settlement): JsonResponse
    {
        if ($settlement->status->value === 'paid') {
            throw new ApiException('invalid_transition', 'تم دفع هذه التسوية بالفعل', 422);
        }

        $validated = $request->validate(['reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500']]);

        $settlement->update([...$validated, 'status' => 'paid', 'paid_at' => now()]);

        AuditLogger::record($request->user(), 'settlements.pay', $settlement, $validated);

        return ApiResponse::success($settlement->fresh(), 'تم تسجيل الدفع بنجاح');
    }
}
