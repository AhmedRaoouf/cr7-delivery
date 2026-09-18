<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ApiResponse;
use App\Support\Lists\ListParams;
use App\Support\Lists\SortOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * doc 08 §7 — «All admin list endpoints accept the global filters `filter[city_id]`,
 * `filter[zone_id]`, `filter[date_from]`, `filter[date_to]`».
 *
 * Half of them did not. `/admin/orders`, `/admin/complaints` and the direct-order lists
 * read `filter.*`; `/admin/restaurants`, `/admin/drivers`, `/admin/customers`,
 * `/admin/zones`, `/admin/pricing-rules` and `/restaurant/products` read the bare name.
 * Both dashboards send the documented spelling, so every search box and city picker on
 * those screens **did nothing at all** — no error, no empty result, just the same
 * unfiltered page back, which is the failure mode nobody reports because it looks like
 * the data.
 *
 * The bare name is still accepted. `GET /restaurants` publishes `?city_id=` to the
 * customer app (doc 08 §4) and the Flutter client sends it that way, so dropping the
 * fallback would fix a dashboard and break a phone.
 *
 * Batch 68 — the typed readers (enums, id lists, Cairo date ranges, sort, `per_page`)
 * live in {@see ListParams}; this trait is the controller-side shorthand for them, and
 * {@see self::paginated()} is the one place the paging `meta` is written. ADMIN-LISTS.md
 * is the contract.
 */
trait FiltersLists
{
    protected function listParams(Request $request): ListParams
    {
        return ListParams::of($request);
    }

    /** '' when neither spelling is present, so `->isNotEmpty()` reads naturally at the call site. */
    protected function filterString(Request $request, string $key): string
    {
        return ListParams::of($request)->string($key);
    }

    /** Null rather than 0, so «no filter» and «id 0» stay distinguishable. */
    protected function filterInt(Request $request, string $key): ?int
    {
        return ListParams::of($request)->id($key);
    }

    /** Null when absent; `?filter[overdue]=0` is a real answer and stays false. */
    protected function filterBool(Request $request, string $key): ?bool
    {
        return ListParams::of($request)->bool($key);
    }

    protected function filterDate(Request $request, string $key): ?string
    {
        $value = $this->filterString($request, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $aliases
     * @return list<string>
     */
    protected function filterEnum(Request $request, string $key, array $allowed, array $aliases = []): array
    {
        return ListParams::of($request)->enum($key, $allowed, $aliases);
    }

    /** @return list<int|string> */
    protected function filterIds(Request $request, string $key, array $words = []): array
    {
        return ListParams::of($request)->ids($key, $words);
    }

    protected function clampedPerPage(Request $request, int $default = 20): int
    {
        return ListParams::of($request)->perPage($default);
    }

    /**
     * `whereIn` for a list, nothing for an empty one.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int|string>  $values
     * @return Builder<TModel>
     */
    protected function whereInList(Builder $query, string $column, array $values): Builder
    {
        return $values === [] ? $query : $query->whereIn($column, $values);
    }

    /**
     * Inclusive `[from, to]` on a timestamp column, either end optional.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{0: ?Carbon, 1: ?Carbon}  $range
     * @return Builder<TModel>
     */
    protected function whereBetweenDates(Builder $query, string $column, array $range): Builder
    {
        [$from, $to] = $range;

        return $query
            ->when($from, fn ($q) => $q->where($column, '>=', $from))
            ->when($to, fn ($q) => $q->where($column, '<=', $to));
    }

    /**
     * The paging envelope every list answers with (ADMIN-LISTS.md §0.5).
     *
     * @param  mixed  $items  the rows to publish; the paginator's own items when null
     * @param  array<string, mixed>  $meta  endpoint-specific extras
     */
    protected function paginated(LengthAwarePaginator $page, mixed $items = null, array $meta = [], ?SortOrder $sort = null): JsonResponse
    {
        return ApiResponse::success($items ?? $page->items(), meta: [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            ...($sort !== null ? ['sort' => (string) $sort] : []),
            ...$meta,
        ]);
    }
}
