<?php

namespace Tests\Feature;

use App\Actions\Admin\ModerateAccountAction;
use App\Models\Driver;
use App\Models\DriverPresenceSession;
use App\Models\Role;
use App\Models\User;
use App\Support\Attendance;
use App\Support\DriverPresence;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsListFixtures;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 99 §2 — D-170. «تسجيل الحضور والانصراف … أول تسجيل أون لاين للطيار يحسب كتسجيل حضور
 * مع ربطه بلوكيشن التغطية … آخر مرة يكون أون لاين كانصراف مع بيان حالات عدم الاتصال».
 *
 * November, so Cairo is UTC+2 with no clock change anywhere near: 08:00 Cairo is 06:00Z.
 */
class CaptainAttendanceTest extends TestCase
{
    use BuildsListFixtures;
    use BuildsTrips;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        $this->cairo('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ the captain's day

    public function test_online_offline_online_on_one_day_is_one_check_in_one_gap_and_two_stretches(): void
    {
        $captain = $this->offlineCaptain();

        $this->switchTo($captain, true);
        $this->assertSame(1, DriverPresenceSession::query()->whereNull('ended_at')->count());

        $this->keepAlive($captain, '2026-11-10 08:02:00', '2026-11-10 10:00:00', step: 120);
        $this->switchTo($captain, false);

        $closed = DriverPresenceSession::query()->sole();
        $this->assertSame('went_offline', $closed->end_reason);
        $this->assertSame('2026-11-10 06:00:00', $closed->started_at->toDateTimeString());
        $this->assertSame('2026-11-10 08:00:00', $closed->ended_at->toDateTimeString());

        $this->cairo('2026-11-10 11:00:00');
        $this->switchTo($captain, true);
        $this->keepAlive($captain, '2026-11-10 11:02:00', '2026-11-10 12:00:00', step: 120);

        $day = $this->asCaptain($captain)->getJson('/api/v1/driver/attendance/today')->assertOk()->json('data');

        $this->assertSame('2026-11-10', $day['date']);
        $this->assertTrue($day['is_online']);
        $this->assertSame('2026-11-10T06:00:00.000000Z', $day['checked_in_at']);
        $this->assertTrue($day['checked_in_in_coverage']);
        $this->assertSame(3 * 3600, $day['online_seconds']);
        $this->assertSame(3600, $day['gap_seconds']);
        $this->assertSame(1, $day['gaps_count']);
        $this->assertNull($day['checked_out_at'], 'still on shift: no check-out is inferred mid-day');
        $this->assertCount(2, $day['sessions']);

        $timeline = Attendance::day($captain->fresh('user'), '2026-11-10')['timeline'];
        $this->assertSame(['online', 'gap', 'online'], array_column($timeline, 'kind'));
        $this->assertSame('went_offline', $timeline[1]['reason']);
        $this->assertSame('أوقف الكابتن استقبال الطلبات', $timeline[1]['reason_ar']);
    }

    public function test_the_last_switch_off_of_the_day_is_the_check_out(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);
        $this->keepAlive($captain, '2026-11-10 08:02:00', '2026-11-10 16:30:00', step: 120);

        $this->switchTo($captain, false);

        $day = Attendance::day($captain->fresh('user'), '2026-11-10');
        $this->assertSame('2026-11-10 14:30:00', $day['checked_out_at']->toDateTimeString());
        $this->assertSame('actual', $day['checkout_kind']);
        $this->assertSame('offline', $day['state']);
        $this->assertSame(0, $day['gaps_count']);
        $this->assertSame((int) (8.5 * 3600), $day['online_seconds']);
    }

    public function test_coverage_is_true_inside_the_captains_area_false_outside_and_null_without_a_fix(): void
    {
        $inside = $this->offlineCaptain();
        $inside->assignedZones()->sync([$this->zone->id]);
        $this->switchTo($inside, true);
        $this->assertTrue(DriverPresenceSession::query()->where('driver_id', $inside->id)->sole()->start_in_coverage);
        $this->assertSame($this->zone->id, DriverPresenceSession::query()->where('driver_id', $inside->id)->sole()->start_zone_id);

        // 40 km out to sea: inside nobody's area and no served city.
        $outside = $this->offlineCaptain('كابتن بعيد', '01011110002', 31.75, 31.95);
        $outside->assignedZones()->sync([$this->zone->id]);
        $this->switchTo($outside, true);
        $this->assertFalse(DriverPresenceSession::query()->where('driver_id', $outside->id)->sole()->start_in_coverage);

        $blind = $this->offlineCaptain('كابتن بلا موقع', '01011110003');
        $blind->update(['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]);
        $this->switchTo($blind, true);
        $session = DriverPresenceSession::query()->where('driver_id', $blind->id)->sole();
        $this->assertNull($session->start_in_coverage);

        // The session's first fix decides it, not a later one.
        $this->asCaptain($blind)->postJson('/api/v1/driver/location', ['latitude' => 31.3840, 'longitude' => 31.4840])->assertOk();
        $this->assertTrue($session->fresh()->start_in_coverage);
        $this->asCaptain($blind)->postJson('/api/v1/driver/location', ['latitude' => 31.75, 'longitude' => 31.95])->assertOk();
        $this->assertTrue($session->fresh()->start_in_coverage);
    }

    public function test_a_lost_signal_splits_the_day_at_the_last_sign_of_life_and_says_why(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $this->cairo('2026-11-10 08:00:40');
        $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();

        // Ten minutes of nothing, then the phone is back.
        $this->cairo('2026-11-10 08:10:40');
        $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();

        $sessions = DriverPresenceSession::query()->orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame('signal_lost', $sessions[0]->end_reason);
        $this->assertSame('2026-11-10 06:00:40', $sessions[0]->ended_at->toDateTimeString(), 'closed at its last sign of life, not when we noticed');
        $this->assertNull($sessions[1]->ended_at);
        $this->assertTrue($captain->fresh()->is_online, 'the switch is the captain\'s');

        $day = Attendance::day($captain->fresh('user'), '2026-11-10');
        $this->assertSame(1, $day['gaps_count']);
        $this->assertSame(600, $day['gap_seconds']);
        $this->assertSame('signal_lost', $day['timeline'][1]['reason']);
        $this->assertSame('انقطع الاتصال — السبب غير معروف', $day['timeline'][1]['reason_ar']);
        $this->assertSame(40, $day['online_seconds'], 'only the stretches the phone was heard: 40 s, then 0 s so far');
    }

    public function test_the_tick_closes_a_silent_session_and_never_touches_the_switch(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $this->cairo('2026-11-10 08:05:00');
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();

        $session = DriverPresenceSession::query()->sole();
        $this->assertSame('signal_lost', $session->end_reason);
        $this->assertSame('2026-11-10 06:00:00', $session->ended_at->toDateTimeString());
        $this->assertTrue($captain->fresh()->is_online);

        // A second tick changes nothing.
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();
        $this->assertSame(1, DriverPresenceSession::query()->count());
    }

    public function test_signing_out_is_a_check_out(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $this->cairo('2026-11-10 08:02:00');
        $this->asCaptain($captain)->postJson('/api/v1/auth/logout')->assertOk();

        $session = DriverPresenceSession::query()->sole();
        $this->assertSame('logged_out', $session->end_reason);
        $this->assertFalse($captain->fresh()->is_online);
        $this->assertSame('actual', Attendance::day($captain->fresh('user'), '2026-11-10')['checkout_kind']);
    }

    public function test_switching_off_after_a_silence_ends_the_silent_stretch_where_it_went_quiet(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $this->cairo('2026-11-10 09:00:00');
        $this->switchTo($captain, false);

        $rows = DriverPresenceSession::query()->orderBy('id')->get();
        $this->assertSame(['signal_lost', 'went_offline'], $rows->pluck('end_reason')->all());
        $this->assertSame('2026-11-10 06:00:00', $rows[0]->ended_at->toDateTimeString());

        $day = Attendance::day($captain->fresh('user'), '2026-11-10');
        $this->assertSame(0, $day['online_seconds']);
        $this->assertSame('2026-11-10 07:00:00', $day['checked_out_at']->toDateTimeString());
        $this->assertSame(3600, $day['gap_seconds']);
    }

    public function test_a_block_ends_the_session_as_blocked(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $this->cairo('2026-11-10 08:01:00');
        app(ModerateAccountAction::class)->block($this->globalAdmin(), $captain->user, 'مخالفة');

        $session = DriverPresenceSession::query()->sole();
        $this->assertSame('blocked', $session->end_reason);
        $this->assertSame('2026-11-10 06:01:00', $session->ended_at->toDateTimeString());
        $this->assertFalse($captain->fresh()->is_online);
        $this->assertNull(Attendance::day($captain->fresh('user'), '2026-11-10')['checked_out_at'], 'a block is not the captain checking out');
    }

    public function test_a_session_across_cairo_midnight_is_split_between_the_two_days_and_never_counted_twice(): void
    {
        $this->cairo('2026-11-10 23:00:00');
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        // Polled every 25 s (a live socket) until 01:00 the next morning.
        for ($t = Carbon::parse('2026-11-10 23:00:25', 'Africa/Cairo'); $t->lte(Carbon::parse('2026-11-11 01:00:00', 'Africa/Cairo')); $t->addSeconds(25)) {
            Carbon::setTestNow($t->copy()->utc());
            $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();
        }

        $this->assertSame(1, DriverPresenceSession::query()->count(), 'one unbroken session');

        $first = Attendance::day($captain->fresh('user'), '2026-11-10');
        $second = Attendance::day($captain->fresh('user'), '2026-11-11');

        $this->assertSame(3600, $first['online_seconds']);
        $this->assertSame('2026-11-10 21:00:00', $first['checked_in_at']->toDateTimeString());
        $this->assertSame('estimated', $first['checkout_kind'], 'the day is over and nobody switched off');
        $this->assertSame('2026-11-10 22:00:00', $first['last_seen_at']->toDateTimeString(), 'clipped at Cairo midnight');

        $this->assertSame('2026-11-10 22:00:00', $second['checked_in_at']->toDateTimeString(), '00:00 Cairo is 22:00Z');
        $this->assertSame(3600, $second['online_seconds']);
        $this->assertSame(7200, $first['online_seconds'] + $second['online_seconds']);
    }

    public function test_the_day_is_a_cairo_day_not_a_utc_day(): void
    {
        // 23:30Z on the 10th is 01:30 on the 11th in Cairo.
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30:00', 'UTC'));
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        [$start, $end] = Attendance::bounds('2026-11-11');
        $this->assertSame('2026-11-10 22:00:00', $start->toDateTimeString());
        $this->assertSame('2026-11-11 22:00:00', $end->toDateTimeString());

        $this->assertSame('absent', Attendance::day($captain->fresh('user'), '2026-11-10')['state']);
        $this->assertSame('2026-11-11', $this->asCaptain($captain)->getJson('/api/v1/driver/attendance/today')->json('data.date'));
        $this->assertSame('online', Attendance::day($captain->fresh('user'), '2026-11-11')['state']);
    }

    public function test_one_open_session_per_captain_is_a_fact_of_the_database(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        // Two touches at the same instant: still one open row.
        DriverPresence::touch($captain->fresh());
        DriverPresence::touch($captain->fresh());
        $this->assertSame(1, DriverPresenceSession::query()->whereNull('ended_at')->count());

        $this->expectException(QueryException::class);
        DriverPresenceSession::query()->create(['driver_id' => $captain->id, 'started_at' => now(), 'last_seen_at' => now()]);
    }

    // ------------------------------------------------------------ the heartbeat

    public function test_many_polls_make_few_writes(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        DB::enableQueryLog();

        for ($i = 1; $i <= 40; $i++) {
            $this->cairo('2026-11-10 08:00:00', $i);
            $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();
        }

        $writes = collect(DB::getQueryLog())
            ->filter(fn ($q) => preg_match('/^(update|insert into) `driver_presence_sessions`/i', $q['query']) === 1)
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $writes, '40 polls in 40 seconds: one write per 30 s');
        $this->assertGreaterThanOrEqual(1, $writes);
        $this->assertSame('2026-11-10 06:00:31', DriverPresenceSession::query()->sole()->last_seen_at->toDateTimeString());
    }

    public function test_offer_polls_from_a_1_0_23_app_keep_a_session_alive_with_no_app_change(): void
    {
        // Online before this shipped: no session yet.
        $captain = $this->captain('كابتن قديم', 31.3845, 31.4845, ['location_updated_at' => now()]);

        for ($s = 0; $s <= 600; $s += 25) {
            $this->cairo('2026-11-10 08:00:00', $s);
            $this->asCaptain($captain)->withHeader('X-App-Version', '1.0.23')->getJson('/api/v1/driver/offers')->assertOk();

            if ($s === 300) {
                $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();
            }
        }

        $this->assertSame(1, DriverPresenceSession::query()->count());
        $session = DriverPresenceSession::query()->sole();
        $this->assertNull($session->ended_at);
        $this->assertTrue($session->start_in_coverage);

        $day = Attendance::day($captain->fresh('user'), '2026-11-10');
        $this->assertSame(0, $day['gaps_count']);
        $this->assertSame(600, $day['online_seconds']);
    }

    public function test_an_offline_captains_requests_open_nothing(): void
    {
        $captain = $this->offlineCaptain();
        $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();
        $this->asCaptain($captain)->postJson('/api/v1/driver/location', ['latitude' => 31.384, 'longitude' => 31.484])->assertOk();

        $this->assertSame(0, DriverPresenceSession::query()->count());
    }

    // ------------------------------------------------------------ the office

    public function test_the_admin_list_has_every_active_captain_absent_ones_included(): void
    {
        $present = $this->offlineCaptain('كابتن حاضر', '01011110001');
        $present->assignedZones()->sync([$this->zone->id]);
        $this->switchTo($present, true);

        $absent = $this->offlineCaptain('كابتن غائب', '01011110002');
        $absent->assignedZones()->sync([$this->zone->id]);

        $this->offlineCaptain('كابتن موقوف', '01011110003')->update(['is_active' => false]);

        $this->keepAlive($present, '2026-11-10 08:00:25', '2026-11-10 09:00:00');

        $response = $this->actingAsAdmin($this->globalAdmin())->getJson('/api/v1/admin/attendance?date=2026-11-10')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $rows = collect($response->json('data'))->keyBy('driver.id');
        $this->assertSame('online', $rows[$present->id]['state']);
        $this->assertSame('2026-11-10T06:00:00.000000Z', $rows[$present->id]['checked_in_at']);
        $this->assertTrue($rows[$present->id]['checked_in_in_coverage']);
        $this->assertSame(3600, $rows[$present->id]['online_seconds']);
        $this->assertSame('absent', $rows[$absent->id]['state']);
        $this->assertNull($rows[$absent->id]['checked_in_at']);
        $this->assertSame(['online' => 1, 'offline' => 0, 'absent' => 1], $response->json('meta.counts'));

        $absentOnly = $this->getJson('/api/v1/admin/attendance?date=2026-11-10&filter[state]=absent')->assertOk()->json('data');
        $this->assertSame([$absent->id], array_map(fn ($row) => $row['driver']['id'], $absentOnly));

        // Encoded, as a browser sends it — a raw UTF-8 query string reaches the controller garbled.
        $this->getJson('/api/v1/admin/attendance?'.http_build_query(['filter' => ['search' => 'حاضر'], 'date' => '2026-11-10']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.driver.id', $present->id);
        $this->getJson('/api/v1/admin/attendance?date=2026-13-40')->assertStatus(422);
        $this->getJson('/api/v1/admin/attendance?filter[state]=sleeping')->assertStatus(422);
    }

    public function test_one_captains_timeline_and_the_scope_and_permission_gates(): void
    {
        $mine = $this->offlineCaptain('كابتن جمصة', '01011110001');
        $mine->assignedZones()->sync([$this->zone->id]);
        $this->switchTo($mine, true);

        $theirs = $this->offlineCaptain('كابتن المنصورة', '01011110002');
        $theirs->assignedZones()->sync([$this->otherZone->id]);

        $this->cairo('2026-11-10 08:02:00');
        $this->switchTo($mine, false);

        $cityAdmin = $this->cityAdmin($this->city);
        $detail = $this->actingAsAdmin($cityAdmin)->getJson("/api/v1/admin/drivers/{$mine->id}/attendance?date=2026-11-10")->assertOk()->json('data');
        $this->assertSame('offline', $detail['state']);
        $this->assertSame([['kind' => 'online', 'seconds' => 120]], array_map(fn ($r) => ['kind' => $r['kind'], 'seconds' => $r['seconds']], $detail['timeline']));
        $this->assertSame('actual', $detail['checkout_kind']);

        // Out of scope is 404, never the row.
        $this->getJson("/api/v1/admin/drivers/{$theirs->id}/attendance")->assertNotFound();
        $this->assertSame([$mine->id], array_map(fn ($r) => $r['driver']['id'], $this->getJson('/api/v1/admin/attendance?date=2026-11-10')->json('data')));

        // No `drivers.view`, no attendance.
        $role = Role::query()->create(['name' => 'support_only', 'guard_name' => 'web', 'label_ar' => 'دعم', 'label_en' => 'Support']);
        $role->givePermissionTo('orders.view');
        $support = User::query()->create(['name' => 'دعم', 'phone' => '01000000077', 'type' => 'admin', 'is_active' => true]);
        $support->assignRole($role);
        $support->adminScopes()->create(['scope_type' => \App\Enums\AdminScopeType::Global]);

        $this->actingAsAdmin($support)->getJson('/api/v1/admin/attendance')->assertForbidden();
        $this->getJson("/api/v1/admin/drivers/{$mine->id}/attendance")->assertForbidden();
        $this->getJson('/api/v1/admin/attendance/export')->assertForbidden();
    }

    public function test_the_day_exports_to_a_workbook(): void
    {
        $captain = $this->offlineCaptain();
        $this->switchTo($captain, true);

        $response = $this->actingAsAdmin($this->globalAdmin())->get('/api/v1/admin/attendance/export?date=2026-11-10');
        $response->assertOk();
        $this->assertStringContainsString('attendance-2026-11-10.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    // ------------------------------------------------------------ helpers

    private function cairo(string $local, int $plusSeconds = 0): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Africa/Cairo')->addSeconds($plusSeconds)->utc());
    }

    private function offlineCaptain(string $name = 'كابتن رامي', ?string $phone = null, float $lat = 31.3845, float $lng = 31.4845): Driver
    {
        $captain = $this->captain($name, $lat, $lng, ['is_online' => false]);

        if ($phone !== null) {
            $captain->user->update(['phone' => $phone]);
        }

        return $captain;
    }

    /** The 1.0.23 app with a live socket: an offer poll every 25 s, ending exactly at `$toLocal`. */
    private function keepAlive(Driver $captain, string $fromLocal, string $toLocal, int $step = 25): void
    {
        $end = Carbon::parse($toLocal, 'Africa/Cairo');

        for ($t = Carbon::parse($fromLocal, 'Africa/Cairo'); $t->lt($end); $t->addSeconds($step)) {
            Carbon::setTestNow($t->copy()->utc());
            $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();
        }

        Carbon::setTestNow($end->copy()->utc());
        $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk();
    }

    private function switchTo(Driver $captain, bool $online): void
    {
        $this->asCaptain($captain)->postJson('/api/v1/driver/status', ['is_online' => $online])->assertOk();
    }
}
