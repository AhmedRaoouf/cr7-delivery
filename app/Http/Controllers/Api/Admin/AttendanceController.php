<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Attendance;
use App\Support\BusinessTime;
use App\Support\Lists\ListParams;
use App\Support\Lists\ListSearch;
use App\Support\ReportExporter;
use App\Support\ReviewAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * doc 99 §2 — D-170. `ADM-59` «الحضور»: every active captain's day, absent ones included,
 * one captain's timeline, and the same day as a workbook.
 *
 * The rows are computed from the sessions in PHP rather than in SQL: a day's figures are
 * clipped per Cairo day and per gap, and the fleet is tens of captains, not thousands.
 * `filter[state]` and the sort therefore apply before paging, on the whole scoped fleet.
 */
class AttendanceController extends Controller
{
    use FiltersLists;

    private const SORTS = ['checked_in_at', 'name', 'online_seconds', 'last_seen_at', 'gap_seconds'];

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $date = $this->date($request);
        $sort = $params->sort(self::SORTS, 'checked_in_at');
        $rows = $this->rows($request, $date);

        if (($states = $params->enum('state', ['online', 'offline', 'absent'])) !== []) {
            $rows = $rows->filter(fn (array $row) => in_array($row['state'], $states, true));
        }

        $rows = $this->sorted($rows, $sort->field, $sort->descending)->values();
        $perPage = $params->perPage();
        $page = max(1, (int) $request->query('page', 1));

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
        );

        return $this->paginated($paginator, sort: $sort, meta: [
            'date' => $date,
            'counts' => [
                'online' => $rows->where('state', 'online')->count(),
                'offline' => $rows->where('state', 'offline')->count(),
                'absent' => $rows->where('state', 'absent')->count(),
            ],
        ]);
    }

    public function show(Request $request, Driver $driver): JsonResponse
    {
        abort_unless(
            AdminScope::applyToDrivers(Driver::query(), $request->user())->whereKey($driver->id)->exists(),
            404,
        );

        return ApiResponse::success(Attendance::day($driver->load('user'), $this->date($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $date = $this->date($request);
        $rows = $this->sorted($this->rows($request, $date), 'checked_in_at', false)->values();
        $states = ['online' => 'متصل الآن', 'offline' => 'غير متصل', 'absent' => 'لم يحضر'];

        return ReportExporter::stream(
            "attendance-{$date}.xlsx",
            'حضور الكباتن',
            ['الكابتن', 'رقم الهاتف', 'الحالة', 'الحضور', 'داخل التغطية', 'آخر ظهور', 'الانصراف', 'نوع الانصراف', 'مدة الاتصال (دقيقة)', 'مدة الانقطاع (دقيقة)', 'عدد مرات الانقطاع'],
            (function () use ($rows, $states) {
                foreach ($rows as $row) {
                    yield [
                        $row['driver']['name'],
                        $row['driver']['phone'],
                        $states[$row['state']] ?? $row['state'],
                        self::clock($row['checked_in_at']),
                        $row['checked_in_in_coverage'] === null ? '—' : ($row['checked_in_in_coverage'] ? 'نعم' : 'لا'),
                        self::clock($row['last_seen_at']),
                        self::clock($row['checked_out_at']),
                        match ($row['checkout_kind']) {
                            'actual' => 'فعلي',
                            'estimated' => 'تقديري (آخر ظهور)',
                            default => '—',
                        },
                        round($row['online_seconds'] / 60, 1),
                        round($row['gap_seconds'] / 60, 1),
                        $row['gaps_count'],
                    ];
                }
            })(),
            [['اليوم', $date]],
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(Request $request, string $date): Collection
    {
        $params = $this->listParams($request);

        $query = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->where('drivers.is_active', true)
            ->with('user:id,name,phone,avatar')
            ->tap(fn ($q) => ReviewAccess::exceptFromDrivers($q));

        $this->whereInList($query, 'drivers.city_id', $params->ids('city_id'));

        ListSearch::apply($query, $params->search(), [
            'refs' => ['vehicle_plate'],
            'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        $drivers = $query->get();
        $sessions = Attendance::sessionsFor($drivers->pluck('id')->map(fn ($id) => (int) $id)->all(), $date);

        return $drivers->map(fn (Driver $driver) => Attendance::day($driver, $date, $sessions->get((int) $driver->id, collect())));
    }

    /**
     * Rows with no figure for the sort key (an absent captain has no check-in) go last
     * whichever way the sort runs.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sorted(Collection $rows, string $field, bool $descending): Collection
    {
        $key = fn (array $row) => match ($field) {
            'name' => (string) $row['driver']['name'],
            'online_seconds', 'gap_seconds' => $row[$field],
            default => $row[$field]?->getTimestamp(),
        };

        $present = $rows->filter(fn (array $row) => $key($row) !== null);
        $missing = $rows->filter(fn (array $row) => $key($row) === null)->sortBy(fn (array $row) => $row['driver']['id']);

        $present = $present->sort(function (array $a, array $b) use ($key, $descending) {
            $cmp = $key($a) <=> $key($b) ?: $a['driver']['id'] <=> $b['driver']['id'];

            return $descending ? -$cmp : $cmp;
        });

        return $present->concat($missing);
    }

    /** `date` / `filter[date]` — a Cairo calendar day; today when absent. */
    private function date(Request $request): string
    {
        $value = ListParams::of($request)->string('date');

        if ($value === '') {
            return BusinessTime::date();
        }

        try {
            $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                && Carbon::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (\Throwable) {
            $valid = false;
        }

        if (! $valid) {
            throw ValidationException::withMessages(['date' => [__('تاريخ غير صالح، الصيغة YYYY-MM-DD')]]);
        }

        return $value;
    }

    private static function clock(?Carbon $moment): string
    {
        return $moment === null ? '—' : BusinessTime::localTime($moment);
    }
}
