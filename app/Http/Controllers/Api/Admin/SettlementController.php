<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\GenerateSettlementAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\BusinessTime;
use App\Support\Lists\ListSearch;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\SettlementExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §7 — GET /admin/settlements, POST /generate, GET /{id}, POST /{id}/pay. */
class SettlementController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['period_end', 'created_at', 'net_amount', 'paid_at'], '-period_end');

        $query = AdminScope::applyToSettlements(Settlement::query(), $request->user());

        $this->whereInList($query, 'settlements.party_type', $params->enum('party_type', ['restaurant', 'driver']));
        $this->whereInList($query, 'settlements.party_id', $params->ids('party_id'));
        $this->whereInList($query, 'settlements.status', $params->enum('status', ['draft', 'pending', 'paid']));

        // The shorthands name a party and its type at once — `party_id` alone is ambiguous
        // between a place and a captain that happen to share an id.
        foreach (['restaurant_id' => 'restaurant', 'driver_id' => 'driver'] as $key => $type) {
            if (($ids = $params->ids($key)) !== []) {
                $query->where('settlements.party_type', $type)->whereIn('settlements.party_id', $ids);
            }
        }

        // A statement belongs to the range when its period overlaps it.
        [$from, $to] = $params->dates();
        $query->when($from, fn ($q) => $q->where('settlements.period_end', '>=', $from->copy()->setTimezone(BusinessTime::timezone())->toDateString()))
            ->when($to, fn ($q) => $q->where('settlements.period_start', '<=', $to->copy()->setTimezone(BusinessTime::timezone())->toDateString()));

        if (($term = $params->search()) !== '') {
            $places = ListSearch::apply(Restaurant::query()->select('restaurants.id'), $term, [
                'names' => ['name_ar', 'name_en'],
                'id' => false,
            ]);
            $captains = Driver::query()->select('drivers.id')->whereHas(
                'user',
                fn ($u) => ListSearch::apply($u, $term, ['names' => ['name'], 'phones' => ['phone'], 'id' => false]),
            );

            $query->where(function ($q) use ($term, $places, $captains) {
                ListSearch::apply($q, $term, ['refs' => ['payment_reference']]);
                $q->orWhere(fn ($p) => $p->where('settlements.party_type', 'restaurant')->whereIn('settlements.party_id', $places))
                    ->orWhere(fn ($d) => $d->where('settlements.party_type', 'driver')->whereIn('settlements.party_id', $captains));
            });
        }

        $settlements = $sort->apply($query, [])->paginate($params->perPage());

        // `party` branches on the row's own `party_type`, so it cannot be eager-loaded:
        // `with('party')` builds the relation from a fresh model whose type is null and
        // would resolve every row against one table. It used to be resolved per row — a
        // query each — and a captain's statement was named nothing, because a captain's
        // name lives on `users`. Two queries for the page now, one per party type.
        $names = $this->partyNames(collect($settlements->items()));

        $rows = collect($settlements->items())->map(fn (Settlement $settlement) => [
            ...$settlement->toArray(),
            'party_name' => $names[$settlement->party_type?->value.':'.$settlement->party_id] ?? null,
        ]);

        return $this->paginated($settlements, $rows, sort: $sort);
    }

    /**
     * `restaurant:<id>` / `driver:<id>` → the name a statement is filed under.
     *
     * @param  Collection<int, Settlement>  $settlements
     * @return array<string, string|null>
     */
    private function partyNames($settlements): array
    {
        $ids = fn (string $type) => $settlements
            ->filter(fn (Settlement $s) => $s->party_type?->value === $type)
            ->pluck('party_id')->unique()->values()->all();

        $names = [];

        foreach (Restaurant::query()->withTrashed()->whereIn('id', $ids('restaurant'))->get(['id', 'name_ar']) as $place) {
            $names['restaurant:'.$place->id] = $place->name_ar;
        }

        foreach (Driver::query()->withTrashed()->with('user:id,name')->whereIn('id', $ids('driver'))->get(['id', 'user_id']) as $captain) {
            $names['driver:'.$captain->id] = $captain->user?->name;
        }

        return $names;
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
