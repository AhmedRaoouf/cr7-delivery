<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

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
 */
trait FiltersLists
{
    /** '' when neither spelling is present, so `->isNotEmpty()` reads naturally at the call site. */
    protected function filterString(Request $request, string $key): string
    {
        $scoped = trim((string) $request->input('filter.'.$key, ''));

        return $scoped !== '' ? $scoped : trim((string) $request->input($key, ''));
    }

    /** Null rather than 0, so «no filter» and «id 0» stay distinguishable. */
    protected function filterInt(Request $request, string $key): ?int
    {
        $value = $this->filterString($request, $key);

        return $value === '' ? null : (int) $value;
    }

    /** Null when absent; `?filter[overdue]=0` is a real answer and stays false. */
    protected function filterBool(Request $request, string $key): ?bool
    {
        $value = $this->filterString($request, $key);

        return $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function filterDate(Request $request, string $key): ?string
    {
        $value = $this->filterString($request, $key);

        return $value === '' ? null : $value;
    }
}
