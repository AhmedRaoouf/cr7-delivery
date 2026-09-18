<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\GenerateSettlementAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\SettlementExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §7 — GET /admin/settlements, POST /generate, GET /{id}, POST /{id}/pay. */
class SettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = AdminScope::applyToSettlements(Settlement::query(), $request->user())
            ->when($request->string('filter.party_type')->isNotEmpty(), fn ($q) => $q->where('party_type', $request->string('filter.party_type')))
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest('period_end')
            ->paginate($request->integer('per_page', 20));

        // `party` branches on the row's own `party_type` (restaurant or driver),
        // so it cannot be eager-loaded: `with('party')` builds the relation from
        // a fresh model whose type is null and would resolve every row against
        // one table. Resolved per row instead — bounded by `per_page` — because
        // a settlement list that cannot name the party names nothing.
        $rows = collect($settlements->items())->map(function (Settlement $settlement) {
            $party = $settlement->party;

            return [
                ...$settlement->toArray(),
                'party_name' => $party?->getAttribute('name_ar')
                    ?? $party?->getAttribute('name'),
            ];
        });

        return ApiResponse::success($rows, meta: [
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

        // Batch 67 — a statement is generated only for a party in the operator's scope.
        $parties = $validated['party_type'] === 'restaurant'
            ? AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            : AdminScope::applyToDrivers(Driver::query(), $request->user());
        abort_unless($parties->whereKey($validated['party_id'])->exists(), 404);

        $settlement = $action->execute($request->user(), $validated['party_type'], $validated['party_id'], $validated['period_start'], $validated['period_end']);

        return ApiResponse::success($settlement, 'تم إنشاء كشف التسوية بنجاح', status: 201);
    }

    public function show(Request $request, Settlement $settlement): JsonResponse
    {
        $this->ensureInScope($request, $settlement);

        return ApiResponse::success($settlement->load(['items.order', 'party', 'createdBy']));
    }

    /**
     * `RES-31` / `ADM-33` — the settlement as a workbook.
     *
     * Streamed rather than built and returned: a settlement covering a busy month is
     * thousands of order lines, and holding the sheet in memory first is how a
     * 128 MB shared-hosting limit is hit by a report nobody thought was large.
     */
    public function export(Request $request, Settlement $settlement): StreamedResponse
    {
        $this->ensureInScope($request, $settlement);

        $party = $settlement->party;
        $name = $party?->getAttribute('name_ar') ?? $party?->getAttribute('name') ?? '';

        return SettlementExporter::stream($settlement, (string) $name);
    }

    public function pay(Request $request, Settlement $settlement): JsonResponse
    {
        $this->ensureInScope($request, $settlement);

        if ($settlement->status->value === 'paid') {
            throw new ApiException('invalid_transition', 'تم دفع هذه التسوية بالفعل', 422);
        }

        $validated = $request->validate(['reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500']]);

        // doc 08 §7 names the request field `reference`; the column is
        // `payment_reference`. Spread straight into `update()` the key matched
        // no fillable attribute, so mass assignment dropped it without a word
        // and every settlement was marked paid with a null reference — the one
        // field that says where the money actually went.
        $settlement->update([
            'payment_reference' => $validated['reference'] ?? null,
            'note' => $validated['note'] ?? null,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        AuditLogger::record($request->user(), 'settlements.pay', $settlement, $validated);

        // doc 62 §4.4 — the place is told its money was sent, and how much.
        $party = $settlement->party;

        if ($party instanceof Restaurant && $party->owner !== null) {
            app(NotificationDispatcher::class)->send(
                $party->owner,
                'settlement.paid',
                [
                    'amount' => number_format((float) $settlement->net_amount, 2).' ج.م',
                    'period' => $settlement->period_start?->format('Y-m-d').' — '.$settlement->period_end?->format('Y-m-d'),
                ],
                '/settlements/'.$settlement->id,
                null,
                ['settlement_id' => (string) $settlement->id, 'kind' => 'settlement'],
            );
        }

        return ApiResponse::success($settlement->fresh(), 'تم تسجيل الدفع بنجاح');
    }

    /** Batch 67 — D-52: a settlement follows its party's scope; outside it, a 404. */
    private function ensureInScope(Request $request, Settlement $settlement): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToSettlements(Settlement::query(), $request->user()), $settlement);
    }
}
