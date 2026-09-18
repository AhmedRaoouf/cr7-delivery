<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\SettlementExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §6 — GET /restaurant/settlements · /{id}. */
class SettlementController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['period_end', 'created_at'], '-period_end');

        $query = Settlement::query()
            ->where('party_type', 'restaurant')
            ->where('party_id', $restaurant->id);

        $this->whereInList($query, 'status', $params->enum('status', ['draft', 'pending', 'paid']));
        self::overlapping($query, $params->dates());

        $settlements = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($settlements, sort: $sort);
    }

    /**
     * A statement belongs to a range when its period overlaps it.
     *
     * @param  Builder<Settlement>  $query
     * @param  array{0: ?Carbon, 1: ?Carbon}  $range
     */
    public static function overlapping($query, array $range): void
    {
        [$from, $to] = $range;

        $query->when($from, fn ($q) => $q->where('period_end', '>=', BusinessTime::localDate($from)))
            ->when($to, fn ($q) => $q->where('period_start', '<=', BusinessTime::localDate($to)));
    }

    public function show(Request $request, Settlement $settlement): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        abort_unless($settlement->party_type->value === 'restaurant' && $settlement->party_id === $restaurant->id, 404);

        return ApiResponse::success($settlement->load('items.order'));
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
        $restaurant = $request->user()->restaurant()->firstOrFail();
        abort_unless($settlement->party_type->value === 'restaurant' && $settlement->party_id === $restaurant->id, 404);

        return SettlementExporter::stream($settlement, $restaurant->name_ar ?? '');
    }
}
