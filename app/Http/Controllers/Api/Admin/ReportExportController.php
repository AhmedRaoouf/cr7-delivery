<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Support\AdminOrderFilters;
use App\Support\AdminScope;
use App\Support\BusinessTime;
use App\Support\ReportExporter;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «تصدير Excel» on every report screen.
 *
 * A separate controller rather than a `?format=` branch inside `ReportController`,
 * because the two answer different questions. The JSON endpoints are **paginated** —
 * they feed a table with 20 rows on it. An export that honoured that would hand the
 * operator page one of their own report and call it a file. Everything here is the whole
 * range, capped at [MAX_ROWS] so a mis-set date cannot stream a million rows through a
 * shared-hosting worker.
 *
 * The permission on each route is the same one the JSON endpoint carries: an operator
 * who may not read a report may not download it either, and the two must not be able to
 * drift apart.
 *
 * PDF is deliberately not here. Arabic in a server-side PDF needs a shaping engine
 * (`mpdf` and 30 MB of vendor, on hosting that cannot run `composer update`), and every
 * lighter library renders «مطاعم» as five disconnected letters, backwards. The dashboard
 * prints the report the browser has already laid out correctly instead — «طباعة / حفظ
 * PDF» on every report screen, against `packages/ui/src/styles/print.css`.
 *
 * The charts follow the same split. The two reports that draw one on screen — sales and
 * customers — pass their series to [ReportExporter], which writes them to a second
 * worksheet «بيانات الرسم البياني». It is **data, not a picture**: openspout cannot write
 * a chart part, and the alternative is a composer dependency this host cannot install.
 * See the note on that class.
 */
class ReportExportController extends Controller
{
    private const MAX_ROWS = 20000;

    public function export(Request $request, string $report): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $stamp = BusinessTime::now()->format('Ymd-Hi');

        return match ($report) {
            // Not a report: the order **list** an operator is looking at, downloaded.
            // It reads its own filters rather than `$from`/`$to`, so a search for one
            // restaurant's Friday exports that and not the last thirty days.
            'orders' => $this->orders($request, $stamp),
            'sales' => $this->sales($request, $from, $to, $stamp),
            'restaurants' => $this->restaurants($request, $from, $to, $stamp),
            'products' => $this->products($stamp),
            'drivers' => $this->drivers($request, $from, $to, $stamp),
            'zones' => $this->zones($request, $from, $to, $stamp),
            'promo-codes' => $this->promoCodes($stamp),
            'refunds' => $this->refunds($from, $to, $stamp),
            'complaints' => $this->complaints($from, $to, $stamp),
            'customers' => $this->customers($request, $stamp),
            default => abort(404),
        };
    }

    /**
     * `ADM-04` as a workbook — «عايزين في الطلبات اللي في الأدمن يطبعها برده اكسل».
     *
     * The row is the row on the screen plus the four columns a table cannot carry on a
     * laptop: the restaurant's own POS number, the captain, the payment method and the
     * money broken into its four parts. An operator reconciling a night with a restaurant
     * needs the breakdown; an operator scanning the board does not, which is why the two
     * differ.
     *
     * `AdminOrderFilters` rather than a second filter chain — see that class. The scope
     * is applied first and separately: a regional admin downloads their own region, and
     * that must not depend on a query parameter being present.
     */
    private function orders(Request $request, string $stamp): StreamedResponse
    {
        $query = AdminOrderFilters::apply(
            AdminScope::applyToOrders(Order::query(), $request->user())->with(['restaurant:id,name_ar', 'driver.user:id,name', 'user:id,name,phone']),
            $request,
        )->latest()->limit(self::MAX_ROWS);

        $summary = [];
        foreach (['status' => 'الحالة', 'origin' => 'المصدر', 'restaurant_id' => 'المطعم'] as $key => $label) {
            $value = trim((string) ($request->input('filter.'.$key) ?? $request->input($key) ?? ''));
            if ($value === '') {
                continue;
            }
            $summary[] = [$label, $key === 'restaurant_id'
                ? (Restaurant::query()->whereKey($value)->value('name_ar') ?? $value)
                : $value];
        }

        return ReportExporter::stream(
            "orders-{$stamp}.xlsx",
            'الطلبات',
            [
                'رقم الطلب', 'رقم الطلب في المطعم', 'التاريخ', 'الوقت', 'المصدر', 'المطعم',
                'العميل', 'هاتف العميل', 'الكابتن', 'الحالة', 'طريقة الدفع',
                'قيمة الأصناف', 'رسوم التوصيل', 'الخصم', 'الإجمالي', 'العمولة',
            ],
            $this->generate($query->cursor(), fn (Order $order) => [
                $order->order_number,
                // The number the restaurant reads back over the phone. Null until the
                // kitchen has answered, and an empty cell says that better than a zero.
                $order->restaurant_order_ref,
                BusinessTime::localDate($order->created_at),
                BusinessTime::localTime($order->created_at),
                $order->origin instanceof \App\Enums\OrderOrigin && $order->origin === \App\Enums\OrderOrigin::RestaurantDirect ? 'مباشر' : 'من التطبيق',
                $order->restaurant?->name_ar ?? $order->restaurant_name_snapshot,
                $order->guest_customer_name ?? $order->user?->name ?? $order->customer_name_snapshot,
                $order->guest_customer_phone ?? $order->user?->phone,
                $order->driver?->user?->name,
                $order->status instanceof OrderStatus ? $order->status->label() : (string) $order->status,
                match ((string) ($order->payment_method instanceof \BackedEnum ? $order->payment_method->value : $order->payment_method)) {
                    'cash' => 'نقدًا',
                    'vodafone_cash' => 'فودافون كاش',
                    'instapay' => 'إنستاباي',
                    default => '—',
                },
                (float) $order->subtotal,
                (float) $order->delivery_fee_share,
                (float) $order->discount_share,
                (float) $order->total,
                (float) $order->commission_amount,
            ]),
            $summary,
        );
    }

    private function sales(Request $request, $from, $to, string $stamp): StreamedResponse
    {
        $rows = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE('.BusinessTime::sqlLocal('created_at').') as date, COUNT(*) as orders_count, SUM(subtotal) as gmv, SUM(commission_amount) as commission, SUM(delivery_fee_share) as delivery_fees, SUM(platform_fee_share) as platform_fees')
            ->groupBy('date')->orderBy('date')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "sales-{$stamp}.xlsx",
            'تقرير المبيعات',
            ['التاريخ', 'عدد الطلبات', 'إجمالي المبيعات', 'العمولة', 'رسوم التوصيل', 'رسوم الخدمة'],
            $this->generate($rows, fn ($row) => [
                (string) $row->date,
                (int) $row->orders_count,
                (float) $row->gmv,
                (float) $row->commission,
                (float) $row->delivery_fees,
                (float) $row->platform_fees,
            ]),
            $this->period($from, $to) + [['إجمالي المبيعات', round((float) $rows->sum('gmv'), 2)]],
            // `ADM-43` draws «المبيعات عبر الزمن» and «عدد الطلبات» off one date axis, so
            // one range with two value columns is what an operator needs to select.
            [
                'title' => 'المبيعات وعدد الطلبات عبر الزمن',
                'headers' => ['التاريخ', 'إجمالي المبيعات', 'عدد الطلبات'],
                'rows' => $rows->map(fn ($row) => [
                    (string) $row->date,
                    (float) $row->gmv,
                    (int) $row->orders_count,
                ])->all(),
            ],
        );
    }

    private function restaurants(Request $request, $from, $to, string $stamp): StreamedResponse
    {
        $rows = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as gmv' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'subtotal')
            ->orderByDesc('gmv')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "restaurants-{$stamp}.xlsx",
            'تقرير المطاعم',
            ['المطعم', 'المنطقة', 'الطلبات المسلَّمة', 'إجمالي المبيعات', 'العمولة %', 'التقييم'],
            $this->generate($rows, fn (Restaurant $r) => [
                $r->name_ar,
                $r->serviceZone?->name_ar,
                (int) $r->delivered_orders_count,
                (float) ($r->gmv ?? 0),
                (float) $r->commission_rate,
                (float) $r->rating_avg,
            ]),
            $this->period($from, $to),
        );
    }

    private function products(string $stamp): StreamedResponse
    {
        $rows = Product::query()->with('restaurant:id,name_ar')
            ->orderByDesc('sold_count')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "products-{$stamp}.xlsx",
            'تقرير الأصناف',
            ['الصنف', 'المطعم', 'السعر', 'عدد المبيعات', 'التقييم'],
            $this->generate($rows, fn (Product $p) => [
                $p->name_ar,
                $p->restaurant?->name_ar,
                (float) $p->price,
                (int) $p->sold_count,
                (float) $p->rating_avg,
            ]),
        );
    }

    private function drivers(Request $request, $from, $to, string $stamp): StreamedResponse
    {
        $rows = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with('user:id,name,phone')
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as earnings' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'driver_earning')
            ->orderByDesc('delivered_orders_count')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "drivers-{$stamp}.xlsx",
            'تقرير الكباتن',
            ['الكابتن', 'رقم الهاتف', 'الطلبات المسلَّمة', 'الأرباح', 'التقييم', 'نوع المركبة'],
            $this->generate($rows, fn (Driver $d) => [
                $d->user?->name,
                $d->user?->phone,
                (int) $d->delivered_orders_count,
                (float) ($d->earnings ?? 0),
                (float) $d->rating_avg,
                $d->vehicle_type?->labelAr(),
            ]),
            $this->period($from, $to),
        );
    }

    private function zones(Request $request, $from, $to, string $stamp): StreamedResponse
    {
        $rows = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('service_zone_id, COUNT(*) as orders_count, SUM(subtotal) as gmv, SUM(delivery_fee_share) as delivery_fees')
            ->with('serviceZone:id,name_ar')
            ->groupBy('service_zone_id')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "zones-{$stamp}.xlsx",
            'تقرير المناطق',
            ['المنطقة', 'عدد الطلبات', 'إجمالي المبيعات', 'رسوم التوصيل'],
            $this->generate($rows, fn ($row) => [
                $row->serviceZone?->name_ar,
                (int) $row->orders_count,
                (float) $row->gmv,
                (float) $row->delivery_fees,
            ]),
            $this->period($from, $to),
        );
    }

    private function promoCodes(string $stamp): StreamedResponse
    {
        $rows = PromoCode::query()->withCount('usages')->withSum('usages', 'discount_amount')
            ->orderByDesc('used_count')->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "promo-codes-{$stamp}.xlsx",
            'تقرير أكواد الخصم',
            ['الكود', 'النوع', 'القيمة', 'مرات الاستخدام', 'إجمالي الخصم', 'نشط'],
            $this->generate($rows, fn (PromoCode $c) => [
                $c->code,
                $c->type,
                (float) $c->value,
                (int) $c->usages_count,
                (float) ($c->usages_sum_discount_amount ?? 0),
                $c->is_active ? 'نعم' : 'لا',
            ]),
        );
    }

    private function refunds($from, $to, string $stamp): StreamedResponse
    {
        $rows = Refund::query()->with('order:id,order_number')
            ->whereBetween('created_at', [$from, $to])
            ->latest()->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "refunds-{$stamp}.xlsx",
            'تقرير المبالغ المستردة',
            ['رقم الطلب', 'المبلغ', 'السبب', 'الحالة', 'تاريخ الطلب', 'تاريخ التنفيذ'],
            $this->generate($rows, fn (Refund $r) => [
                $r->order?->order_number ?? (string) $r->order_id,
                (float) $r->amount,
                $r->reason_code,
                $r->status,
                $r->created_at?->format('Y-m-d H:i'),
                $r->processed_at?->format('Y-m-d H:i'),
            ]),
            $this->period($from, $to),
        );
    }

    private function complaints($from, $to, string $stamp): StreamedResponse
    {
        $rows = Complaint::query()->with('user:id,name')
            ->whereBetween('created_at', [$from, $to])
            ->latest()->limit(self::MAX_ROWS)->get();

        return ReportExporter::stream(
            "complaints-{$stamp}.xlsx",
            'تقرير الشكاوى',
            ['رقم التذكرة', 'المشتكي', 'التصنيف', 'الأولوية', 'الحالة', 'أُنشئت', 'حُلّت'],
            $this->generate($rows, fn (Complaint $c) => [
                $c->ticket_number,
                $c->user?->name,
                $c->category,
                $c->priority,
                $c->status,
                $c->created_at?->format('Y-m-d H:i'),
                $c->resolved_at?->format('Y-m-d H:i'),
            ]),
            $this->period($from, $to),
        );
    }

    private function customers(Request $request, string $stamp): StreamedResponse
    {
        $segment = $request->string('filter.segment')->value();

        $query = User::query()->where('type', 'customer');
        $query = match ($segment) {
            'new' => $query->where('created_at', '>=', BusinessTime::startOfDaysAgo(30)),
            'returning' => $query->whereHas('orderGroups', fn ($q) => $q->where('rollup_status', 'delivered'), '>=', 2),
            'restricted' => $query->whereNotNull('cash_only_until')->where('cash_only_until', '>', now()),
            default => $query,
        };

        $rows = $query->withCount('orderGroups')->latest()->limit(self::MAX_ROWS)->get();

        // `ADM-52`'s retention grid, from the same query that feeds it on screen.
        $cohorts = User::query()
            ->where('type', 'customer')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as cohort_month, COUNT(*) as customers_count")
            ->groupBy('cohort_month')
            ->orderBy('cohort_month')
            ->limit(6)
            ->get();

        return ReportExporter::stream(
            "customers-{$stamp}.xlsx",
            'تقرير العملاء',
            ['الاسم', 'رقم الهاتف', 'عدد الطلبات', 'تاريخ التسجيل', 'آخر دخول', 'نشط'],
            $this->generate($rows, fn (User $u) => [
                $u->name,
                $u->phone,
                (int) $u->order_groups_count,
                $u->created_at?->format('Y-m-d'),
                $u->last_login_at?->format('Y-m-d H:i'),
                $u->is_active ? 'نعم' : 'لا',
            ]),
            [['الشريحة', $segment ?: 'الكل']],
            [
                'title' => 'شبكة الاحتفاظ — العملاء لكل شهر تسجيل',
                'headers' => ['شهر التسجيل', 'عدد العملاء'],
                'rows' => $cohorts->map(fn ($row) => [
                    (string) $row->cohort_month,
                    (int) $row->customers_count,
                ])->all(),
            ],
        );
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return Generator<int, list<scalar|null>>
     */
    private function generate(iterable $rows, callable $map): Generator
    {
        foreach ($rows as $row) {
            yield $map($row);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function period($from, $to): array
    {
        // The local day, not the UTC instant behind it — see `BusinessTime::localDate`.
        return [['الفترة', BusinessTime::localDate($from).' — '.BusinessTime::localDate($to)]];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        return [
            BusinessTime::fromInput($request->date('filter.date_from')) ?? BusinessTime::startOfDaysAgo(30),
            BusinessTime::fromInput($request->date('filter.date_to'), endOfDay: true) ?? BusinessTime::endOfToday(),
        ];
    }
}
