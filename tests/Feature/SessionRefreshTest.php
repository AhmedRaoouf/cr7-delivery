<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Http\Resources\UserResource;
use App\Models\Driver;
use App\Models\RefreshToken;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * D-120 — a short access token, renewed in the background, and «انتهت الجلسة» only when
 * the renewal itself is refused.
 *
 * Operator, 2026-09-16: places complained of being signed out quickly. The API was not the
 * cause — its tokens never expired — but a token that never expires is also a token that,
 * once copied, works for ever. Every request here goes through the kernel with a real
 * bearer token: the guard, `client.type`, `account.active` and Sanctum's own expiry are
 * what is under test, and `Sanctum::actingAs` would skip all four.
 */
class SessionRefreshTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private const SESSION_KEYS = [
        'token', 'token_type', 'expires_in', 'expires_at',
        'refresh_token', 'refresh_expires_in', 'refresh_expires_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A request as a real client makes it: its own headers and bearer, nothing left over.
     *
     * Sanctum's guard remembers the user it resolved for the life of the application, and
     * a test is one application — without forgetting it, every request after the first is
     * answered as whoever the first one was, whatever token it carries.
     */
    private function client(?string $bearer = null, string $app = 'app', array $headers = []): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['X-App' => $app, 'Accept-Language' => 'ar'] + $headers);

        if ($bearer !== null) {
            $this->withToken($bearer);
        }

        return $this;
    }

    private function signIn(User $user, string $app = 'app', bool $refreshable = true, array $headers = []): TestResponse
    {
        return $this->client(app: $app, headers: $refreshable ? ['X-Auth-Refresh' => '1'] + $headers : $headers)
            ->postJson('/api/v1/auth/login', ['login' => $user->phone, 'password' => 'password'])
            ->assertOk();
    }

    /** @return array<string, mixed> the session block */
    private function signedIn(User $user, string $app = 'app'): array
    {
        return $this->signIn($user, $app)->json('data');
    }

    private function refresh(string $refreshToken, string $app = 'app'): TestResponse
    {
        return $this->client(app: $app)->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    private function me(string $bearer, string $app = 'app'): TestResponse
    {
        return $this->client($bearer, $app)->getJson('/api/v1/auth/me');
    }

    private function assertExpired(TestResponse $response): void
    {
        $response->assertStatus(401)
            ->assertJsonPath('error', 'session_expired')
            ->assertJsonPath('message', 'انتهت الجلسة. سجّل الدخول مرة أخرى.');
    }

    /** The factory's `01#########` is not always a number the sign-in form accepts. */
    private function account(string $type): User
    {
        return User::factory()->create(['type' => $type, 'phone' => '010'.random_int(10000000, 99999999)]);
    }

    private function customer(): User
    {
        return $this->account('customer');
    }

    /** A place whose owner signs in with the same password as every other fixture here. */
    private function place(): Restaurant
    {
        $place = $this->makeRestaurant('بوكس ع السريع');
        $place->owner->forceFill(['password' => Hash::make('password')])->save();

        return $place->fresh('owner');
    }

    private function captain(): Driver
    {
        return Driver::query()->create([
            'user_id' => $this->account('driver')->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_active' => true,
        ])->load('user');
    }

    private function admin(): User
    {
        $admin = $this->account('admin');
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    // ------------------------------------------------------------ the two shapes

    public function test_a_sign_in_with_the_header_answers_the_session_block_beside_the_user(): void
    {
        $user = $this->customer();

        $data = $this->signIn($user, headers: ['X-Device-Id' => 'phone-7'])->json('data');

        $this->assertSame(['user', ...self::SESSION_KEYS], array_keys($data));
        $this->assertSame($user->id, $data['user']['id']);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(3600, $data['expires_in']);
        $this->assertSame(60 * 86400, $data['refresh_expires_in']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64,}$/', $data['refresh_token']);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), strtotime($data['expires_at']), 2);
        $this->assertEqualsWithDelta(now()->addDays(60)->getTimestamp(), strtotime($data['refresh_expires_at']), 2);

        // The access token is a Sanctum token with its own expiry, paired with a refresh row.
        $access = PersonalAccessToken::findToken($data['token']);
        $this->assertNotNull($access->expires_at);

        $row = RefreshToken::query()->sole();
        $this->assertSame($access->id, $row->access_token_id);
        $this->assertSame(hash('sha256', $data['refresh_token']), $row->token_hash);
        $this->assertSame('app', $row->client);
        $this->assertSame('phone-7', $row->device_id);

        $this->me($data['token'])->assertOk()->assertJsonPath('data.id', $user->id);
    }

    /**
     * The 1.0.3 and 1.0.4 apps in the field never send the header, and must get exactly
     * what they always got — rebuilt here the way the controller built it before D-120.
     */
    public function test_without_the_header_the_sign_in_is_byte_identical_to_what_it_was(): void
    {
        $this->freezeTime();

        foreach ([['customer', 'app'], ['restaurant', 'restaurant']] as [$type, $app]) {
            $user = $this->account($type);

            $response = $this->signIn($user, $app, refreshable: false);

            $this->assertSame(['user', 'token'], array_keys($response->json('data')));

            $token = $response->json('data.token');
            $before = ApiResponse::success([
                'user' => new UserResource(User::query()->findOrFail($user->id)),
                'token' => $token,
            ], 'تم تسجيل الدخول بنجاح');

            $this->assertSame($before->getContent(), $response->getContent());

            // …and it is the token it always was: no expiry of its own, nothing beside it.
            $this->assertNull(PersonalAccessToken::findToken($token)->expires_at);
        }

        $this->assertSame(0, RefreshToken::query()->count());
    }

    public function test_the_otp_and_registration_sign_ins_carry_the_same_two_shapes(): void
    {
        $this->otpTestMode('123456');
        Settings::set('auth.register_requires_otp', false, 'otp');

        // OTP sign-in, old shape.
        $this->client()->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])->assertOk();
        $old = $this->client()->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456'])->assertOk();
        $this->assertSame(['user', 'token', 'is_new_user'], array_keys($old->json('data')));

        // OTP sign-in, new shape — the session block between `user` and `is_new_user`.
        $this->travel(2)->minutes();
        $this->client()->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])->assertOk();
        $new = $this->client(headers: ['X-Auth-Refresh' => '1'])
            ->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456'])
            ->assertOk();
        $this->assertSame(['user', ...self::SESSION_KEYS, 'is_new_user'], array_keys($new->json('data')));

        // Registration, both shapes.
        $plain = $this->client()
            ->postJson('/api/v1/auth/register', ['phone' => '01022233344', 'name' => 'منى', 'password' => 'Mona@2026'])
            ->assertCreated();
        $this->assertSame(['user', 'token'], array_keys($plain->json('data')));

        $registered = $this->client(headers: ['X-Auth-Refresh' => '1'])
            ->postJson('/api/v1/auth/register', ['phone' => '01033344455', 'name' => 'سارة', 'password' => 'Sara@2026'])
            ->assertCreated();
        $this->assertSame(['user', ...self::SESSION_KEYS], array_keys($registered->json('data')));

        $this->me($registered->json('data.token'))->assertOk();
    }

    // ------------------------------------------------------------ the access token

    public function test_the_access_token_works_for_an_hour_and_then_answers_unauthenticated(): void
    {
        $session = $this->signedIn($this->customer());

        $this->me($session['token'])->assertOk();

        $this->travel(59)->minutes();
        $this->me($session['token'])->assertOk();

        $this->travel(2)->minutes();
        $this->me($session['token'])->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
    }

    // ------------------------------------------------------------------ refresh

    public function test_a_refresh_rotates_the_pair_and_the_old_access_token_dies(): void
    {
        $user = $this->customer();
        $first = $this->signedIn($user);

        $this->travel(10)->days();

        $second = $this->refresh($first['refresh_token'])->assertOk()->json('data');

        $this->assertSame(self::SESSION_KEYS, array_keys($second));
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        // The window slides: ‪60‬ days from this renewal, not from the sign-in.
        $this->assertEqualsWithDelta(now()->addDays(60)->getTimestamp(), strtotime($second['refresh_expires_at']), 2);
        $this->assertGreaterThan(strtotime($first['refresh_expires_at']), strtotime($second['refresh_expires_at']));

        $this->me($first['token'])->assertStatus(401);
        $this->me($second['token'])->assertOk()->assertJsonPath('data.id', $user->id);

        $rotated = RefreshToken::query()->where('token_hash', hash('sha256', $first['refresh_token']))->sole();
        $next = RefreshToken::query()->where('token_hash', hash('sha256', $second['refresh_token']))->sole();
        $this->assertNotNull($rotated->rotated_at);
        $this->assertSame($next->id, $rotated->replaced_by_id);
        $this->assertSame($rotated->family, $next->family);
        // The pair is held encrypted for the grace window, never in the clear.
        $this->assertStringNotContainsString($second['refresh_token'], (string) $rotated->grace_payload);
        $this->assertSame($second['refresh_token'], json_decode(Crypt::decryptString($rotated->grace_payload), true)['refresh_token']);
    }

    public function test_the_same_refresh_token_within_the_grace_window_answers_the_same_pair(): void
    {
        $session = $this->signedIn($this->customer());

        $first = $this->refresh($session['refresh_token'])->assertOk()->json('data');

        // Five requests that all found the access token expired, arriving together.
        $this->travel(60)->seconds();

        foreach (range(1, 4) as $ignored) {
            $again = $this->refresh($session['refresh_token'])->assertOk()->json('data');

            $this->assertSame($first['token'], $again['token']);
            $this->assertSame($first['refresh_token'], $again['refresh_token']);
            $this->assertSame($first['expires_at'], $again['expires_at']);
            // Counted from now, so a client adding it to its own clock lands on the same instant.
            $this->assertSame($first['expires_in'] - 60, $again['expires_in']);
        }

        $this->assertSame(2, RefreshToken::query()->count(), 'one renewal, however many times it was asked for');
        $this->me($first['token'])->assertOk();
    }

    public function test_a_lost_response_is_recovered_when_its_successor_was_never_used(): void
    {
        $session = $this->signedIn($this->customer());

        // The renewal happened; its answer never reached the client.
        $lost = $this->refresh($session['refresh_token'])->assertOk()->json('data');

        $this->travel(5)->minutes();

        $recovered = $this->refresh($session['refresh_token'])->assertOk()->json('data');

        $this->assertNotSame($lost['token'], $recovered['token']);
        $this->assertNotSame($lost['refresh_token'], $recovered['refresh_token']);
        $this->me($recovered['token'])->assertOk();
        // The pair nobody received is withdrawn, access token and all.
        $this->me($lost['token'])->assertStatus(401);
        $this->assertNotNull(RefreshToken::query()->where('token_hash', hash('sha256', $lost['refresh_token']))->value('revoked_at'));

        // And the recovered pair renews like any other.
        $this->refresh($recovered['refresh_token'])->assertOk();
    }

    /**
     * The one row revoked on its own is the successor a recovery withdrew, and only a
     * second holder can have it.
     */
    public function test_a_withdrawn_successor_presented_later_ends_the_sign_in(): void
    {
        $session = $this->signedIn($this->customer());
        $lost = $this->refresh($session['refresh_token'])->json('data');

        $this->travel(5)->minutes();
        $recovered = $this->refresh($session['refresh_token'])->assertOk()->json('data');

        $this->assertExpired($this->refresh($lost['refresh_token']));

        $this->me($recovered['token'])->assertStatus(401);
        $this->assertExpired($this->refresh($recovered['refresh_token']));
    }

    public function test_a_rotated_token_presented_after_its_successor_was_used_revokes_that_sign_in_only(): void
    {
        $user = $this->customer();
        $phone = $this->signedIn($user);
        $tablet = $this->signedIn($user);

        $renewed = $this->refresh($phone['refresh_token'])->assertOk()->json('data');
        $this->me($renewed['token'])->assertOk();

        // Somebody else kept the old refresh token.
        $this->travel(5)->minutes();
        $this->assertExpired($this->refresh($phone['refresh_token']));

        // Everything descended from that sign-in is gone…
        $this->me($renewed['token'])->assertStatus(401);
        $this->assertExpired($this->refresh($renewed['refresh_token']));

        // …and the same account's other sign-in is untouched.
        $this->me($tablet['token'])->assertOk();
        $this->refresh($tablet['refresh_token'])->assertOk();
    }

    /**
     * The access token's `last_used_at` does not survive `sanctum:prune-expired`; the
     * copy on the refresh row does, so a theft is still a theft a day later.
     */
    public function test_a_used_successor_is_still_known_after_its_access_token_is_pruned(): void
    {
        $session = $this->signedIn($this->customer());
        $renewed = $this->refresh($session['refresh_token'])->assertOk()->json('data');
        $this->me($renewed['token'])->assertOk();

        $this->travel(26)->hours();
        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();
        $this->assertNull(PersonalAccessToken::findToken($renewed['token']));

        $this->assertExpired($this->refresh($session['refresh_token']));
        $this->assertExpired($this->refresh($renewed['refresh_token']));
    }

    public function test_every_refusal_is_the_same_401_and_a_missing_field_is_a_422(): void
    {
        $session = $this->signedIn($this->customer());

        $this->assertExpired($this->refresh(str_repeat('x', 80)));

        $this->travel(61)->days();
        $this->assertExpired($this->refresh($session['refresh_token']));

        $this->client()->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => ['refresh_token']]);
    }

    public function test_refresh_needs_no_bearer_and_is_throttled_in_its_own_words(): void
    {
        foreach (range(1, 30) as $ignored) {
            $this->assertExpired($this->refresh('not-a-token'));
        }

        // A client told to wait must wait — not sign the person out.
        $this->refresh('not-a-token')
            ->assertStatus(429)
            ->assertJsonPath('error', 'refresh_rate_limited');

        // One token hammered is not every token from the address.
        $this->refresh($this->signedIn($this->customer())['refresh_token'])->assertOk();
    }

    // ------------------------------------------------------------------ windows

    public function test_an_admin_session_lasts_fourteen_days_unused_and_the_panel_moves_both_windows(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $adminSession = $this->signedIn($admin, 'admin');
        $this->assertSame(14 * 86400, $adminSession['refresh_expires_in']);
        $this->assertSame(60 * 86400, $this->signedIn($customer)['refresh_expires_in']);

        $this->client($adminSession['token'], 'admin')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['auth.admin_session_days' => 3, 'auth.session_days' => 30]])
            ->assertOk();

        $this->assertSame(3 * 86400, $this->signedIn($admin, 'admin')['refresh_expires_in']);
        $this->assertSame(30 * 86400, $this->signedIn($customer)['refresh_expires_in']);

        // Shortened applies at once: the session issued for ‪14‬ days is gone after ‪4‬.
        $this->travel(4)->days();
        $this->assertExpired($this->refresh($adminSession['refresh_token'], 'admin'));
    }

    public function test_a_session_that_is_used_never_ends(): void
    {
        $session = $this->signedIn($this->customer());

        // Renewed once every ‪50‬ days for a year — well past any fixed cap there could be.
        foreach (range(1, 8) as $ignored) {
            $this->travel(50)->days();
            $session = $this->refresh($session['refresh_token'])->assertOk()->json('data');
        }

        $this->me($session['token'])->assertOk();
    }

    public function test_a_legacy_token_lives_while_it_is_used_within_its_window(): void
    {
        $customer = $this->signIn($this->customer(), refreshable: false)->json('data.token');

        // Used within ‪60‬ days each time: each use starts the window again, so it outlives
        // any fixed age.
        $this->travel(59)->days();
        $this->me($customer)->assertOk();
        $this->travel(59)->days();
        $this->me($customer)->assertOk();

        // An admin's window is ‪14‬.
        $admin = $this->signIn($this->admin(), 'admin', refreshable: false)->json('data.token');
        $this->travel(13)->days();
        $this->me($admin, 'admin')->assertOk();
        $this->travel(15)->days();
        $this->me($admin, 'admin')->assertStatus(401)->assertJsonPath('error', 'unauthenticated');

        // ‪28‬ days is nothing to a customer's; ‪61‬ more, unused, is past it.
        $this->me($customer)->assertOk();
        $this->travel(61)->days();
        $this->me($customer)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
    }

    // ------------------------------------------------------------------ upgrade

    public function test_a_legacy_token_upgrades_to_a_session_once_however_many_times_it_is_asked(): void
    {
        $user = $this->customer();
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');

        $session = $this->client($legacy)->postJson('/api/v1/auth/session/upgrade')->assertOk()->json('data');

        $this->assertSame(self::SESSION_KEYS, array_keys($session));
        $this->me($session['token'])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->refresh($session['refresh_token'])->assertOk();

        // A second upgrade racing the first gets the same pair; a request already on the
        // wire with the old token still lands.
        $again = $this->client($legacy)->postJson('/api/v1/auth/session/upgrade')->assertOk()->json('data');
        $this->assertSame($session['refresh_token'], $again['refresh_token']);
        $this->me($legacy)->assertOk();
        $this->assertSame(1, RefreshToken::query()->whereNull('rotated_at')->count());

        // Two minutes on, the legacy token is gone for good.
        $this->travel(121)->seconds();
        $this->me($legacy)->assertStatus(401);
    }

    public function test_upgrading_a_session_that_already_renews_is_a_409(): void
    {
        $session = $this->signedIn($this->customer());

        $this->client($session['token'])->postJson('/api/v1/auth/session/upgrade')
            ->assertStatus(409)
            ->assertJsonPath('error', 'session_already_refreshable');

        $this->client()->postJson('/api/v1/auth/session/upgrade')->assertStatus(401);
    }

    // ------------------------------------------------------------- revocation

    public function test_signing_out_ends_this_sign_in_and_nothing_else(): void
    {
        $user = $this->customer();
        $phone = $this->signedIn($user);
        $tablet = $this->signedIn($user);
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');

        $phone = $this->refresh($phone['refresh_token'])->json('data');

        $this->client($phone['token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->me($phone['token'])->assertStatus(401);
        $this->assertExpired($this->refresh($phone['refresh_token']));

        $this->me($tablet['token'])->assertOk();
        $this->refresh($tablet['refresh_token'])->assertOk();
        $this->me($legacy)->assertOk();

        // A legacy token signs out the way it always did.
        $this->client($legacy)->postJson('/api/v1/auth/logout')->assertOk();
        $this->me($legacy)->assertStatus(401);
    }

    public function test_signing_out_everywhere_ends_every_session_the_account_holds(): void
    {
        $user = $this->customer();
        $phone = $this->signedIn($user);
        $tablet = $this->signedIn($user);
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');

        $this->client($phone['token'])->postJson('/api/v1/auth/logout-all')->assertOk();

        foreach ([$phone['token'], $tablet['token'], $legacy] as $token) {
            $this->me($token)->assertStatus(401);
        }

        $this->assertExpired($this->refresh($phone['refresh_token']));
        $this->assertExpired($this->refresh($tablet['refresh_token']));
    }

    public function test_a_password_change_keeps_this_session_and_ends_every_other(): void
    {
        $user = $this->customer();
        $here = $this->signedIn($user);
        $elsewhere = $this->signedIn($user);
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');

        $this->client($here['token'])->postJson('/api/v1/auth/password/change', [
            'current_password' => 'password',
            'password' => 'NewPass#2026',
            'password_confirmation' => 'NewPass#2026',
        ])->assertOk();

        $this->me($here['token'])->assertOk();
        $this->refresh($here['refresh_token'])->assertOk();

        $this->me($elsewhere['token'])->assertStatus(401);
        $this->assertExpired($this->refresh($elsewhere['refresh_token']));
        $this->me($legacy)->assertStatus(401);
    }

    public function test_a_password_reset_ends_every_session_refresh_tokens_included(): void
    {
        $channel = $this->fakeOtpChannel();
        $user = $this->customer();
        $session = $this->signedIn($user);
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');

        $this->client()->postJson('/api/v1/auth/password/forgot', ['login' => $user->phone])->assertOk();
        $this->client()->postJson('/api/v1/auth/password/reset', [
            'login' => $user->phone,
            'code' => $channel->lastCodeFor($user->phone),
            'password' => 'NewPass#2026',
            'password_confirmation' => 'NewPass#2026',
        ])->assertOk();

        $this->me($session['token'])->assertStatus(401);
        $this->me($legacy)->assertStatus(401);
        $this->assertExpired($this->refresh($session['refresh_token']));
    }

    public function test_blocking_an_account_ends_every_session_and_its_refresh_says_why(): void
    {
        $customer = $this->customer();
        $session = $this->signedIn($customer);
        $renewed = $this->refresh($session['refresh_token'])->json('data');
        $operator = $this->signedIn($this->admin(), 'admin');

        $this->client($operator['token'], 'admin')
            ->postJson("/api/v1/admin/accounts/{$customer->id}/block", ['reason' => 'بلاغات متكررة'])
            ->assertOk();

        $this->me($renewed['token'])->assertStatus(401);
        $this->assertSame(0, RefreshToken::query()->where('user_id', $customer->id)->whereNull('revoked_at')->count());

        // Not «انتهت الجلسة»: the person is told the account is blocked, and why.
        $this->refresh($renewed['refresh_token'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'account_suspended')
            ->assertJsonPath('message', 'تم إيقاف حسابك — السبب: بلاغات متكررة');
    }

    public function test_an_inactive_account_holding_a_live_session_is_refused_at_refresh_and_revoked(): void
    {
        $customer = $this->customer();
        $session = $this->signedIn($customer);

        // Deactivated behind the API's back — no revocation ran.
        $customer->forceFill(['is_active' => false])->save();

        $this->refresh($session['refresh_token'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'account_suspended')
            ->assertJsonPath('message', 'تم إيقاف حسابك — يرجى التواصل مع الدعم');

        $this->assertNotNull(RefreshToken::query()->sole()->revoked_at);
        $this->assertNull(PersonalAccessToken::findToken($session['token']));
    }

    public function test_deleting_the_account_ends_every_session(): void
    {
        $customer = $this->customer();
        $session = $this->signedIn($customer);

        $this->client($session['token'])->deleteJson('/api/v1/auth/account')->assertOk();

        $this->me($session['token'])->assertStatus(401);
        $this->assertExpired($this->refresh($session['refresh_token']));
    }

    // -------------------------------------------------------------- the office

    /**
     * A password the office replaces is replaced because the old one is lost or leaked,
     * and a session made with it is exactly what must not survive — for a place, a
     * captain and an admin alike, legacy tokens included.
     */
    public function test_an_office_password_reset_ends_every_session_the_account_held(): void
    {
        $this->seedLocation();
        $operator = $this->signedIn($this->admin(), 'admin');
        $place = $this->place();
        $captain = $this->captain();
        $colleague = $this->admin();

        foreach ([
            [$place->owner, 'restaurant', "/api/v1/admin/restaurants/{$place->id}/reset-password"],
            [$captain->user, 'app', "/api/v1/admin/drivers/{$captain->id}/reset-password"],
            [$colleague, 'admin', "/api/v1/admin/users/{$colleague->id}/reset-password"],
        ] as [$account, $app, $route]) {
            $session = $this->signedIn($account, $app);
            $legacy = $this->signIn($account, $app, refreshable: false)->json('data.token');

            $this->client($operator['token'], 'admin')->postJson($route)
                ->assertOk()
                ->assertJsonStructure(['data' => ['temp_password']]);

            $this->me($session['token'], $app)->assertStatus(401);
            $this->me($legacy, $app)->assertStatus(401);
            $this->assertExpired($this->refresh($session['refresh_token'], $app));
            $this->assertSame(0, $account->tokens()->count());
        }

        // The office's own session is not one of them.
        $this->me($operator['token'], 'admin')->assertOk();
    }

    public function test_the_reset_password_command_ends_every_session_the_account_held(): void
    {
        $user = $this->account('driver');
        $session = $this->signedIn($user);
        $legacy = $this->signIn($user, refreshable: false)->json('data.token');
        $bystander = $this->signedIn($this->customer());

        $this->artisan('cr7:reset-password', ['login' => $user->phone])
            ->expectsOutputToContain('every session this account held is signed out')
            ->assertSuccessful();

        $this->me($session['token'])->assertStatus(401);
        $this->me($legacy)->assertStatus(401);
        $this->assertExpired($this->refresh($session['refresh_token']));

        $this->me($bystander['token'])->assertOk();
    }

    // ------------------------------------------------------------------ panel

    public function test_the_two_windows_are_on_the_settings_panel(): void
    {
        $operator = $this->signedIn($this->admin(), 'admin');

        $groups = collect($this->client($operator['token'], 'admin')->getJson('/api/v1/admin/settings/schema')->assertOk()->json('data'))
            ->keyBy('key');

        $this->assertSame('الجلسات', $groups['session']['title_ar']);

        $fields = collect($groups['session']['fields'])->keyBy('key');

        $this->assertSame(['auth.session_days', 'auth.admin_session_days'], $fields->keys()->all());
        $this->assertSame(['int', 60, 7, 365, 60], [
            $fields['auth.session_days']['type'], $fields['auth.session_days']['default'],
            $fields['auth.session_days']['min'], $fields['auth.session_days']['max'],
            $fields['auth.session_days']['value'],
        ]);
        $this->assertSame(['int', 14, 1, 90, 14], [
            $fields['auth.admin_session_days']['type'], $fields['auth.admin_session_days']['default'],
            $fields['auth.admin_session_days']['min'], $fields['auth.admin_session_days']['max'],
            $fields['auth.admin_session_days']['value'],
        ]);
        $this->assertNotEmpty($fields['auth.session_days']['label_ar']);
        $this->assertNotEmpty($fields['auth.admin_session_days']['label_en']);

        $this->client($operator['token'], 'admin')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['auth.admin_session_days' => 120]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['auth.admin_session_days']]);
    }

    // ------------------------------------------------------------------ pruning

    public function test_the_prune_removes_what_can_no_longer_authenticate_and_keeps_what_can(): void
    {
        $user = $this->customer();

        $dead = $this->signedIn($user);
        $this->client($dead['token'])->postJson('/api/v1/auth/logout')->assertOk();

        $rotated = $this->signedIn($user);
        $live = $this->refresh($rotated['refresh_token'])->json('data');

        $idleLegacy = $this->signIn($user, refreshable: false)->json('data.token');

        $this->travel(8)->days();
        $freshLegacy = $this->signIn($user, refreshable: false)->json('data.token');
        $this->refresh($live['refresh_token'])->assertOk();

        // Past a customer's ‪60‬-day window by more than the week of grace.
        PersonalAccessToken::findToken($idleLegacy)->forceFill(['last_used_at' => now()->subDays(68)])->save();

        $this->artisan('cr7:prune-sessions')->assertSuccessful();

        // The signed-out and rotated rows are a week dead; the live one and its
        // predecessor from moments ago are kept.
        $this->assertNull(RefreshToken::query()->where('token_hash', hash('sha256', $dead['refresh_token']))->first());
        $this->assertNull(RefreshToken::query()->where('token_hash', hash('sha256', $rotated['refresh_token']))->first());
        $this->assertNotNull(RefreshToken::query()->where('token_hash', hash('sha256', $live['refresh_token']))->first());
        $this->assertSame(1, RefreshToken::query()->whereNull('rotated_at')->whereNull('revoked_at')->count());

        $this->assertNull(PersonalAccessToken::findToken($idleLegacy));
        $this->assertNotNull(PersonalAccessToken::findToken($freshLegacy));

        // The pair held for the grace window is scrubbed once the window is over.
        $this->travel(3)->minutes();
        $this->artisan('cr7:prune-sessions')->assertSuccessful();
        $this->assertSame(0, RefreshToken::query()->whereNotNull('grace_payload')->count());
    }

    public function test_both_prunes_are_scheduled_with_a_stale_lock_that_heals(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['sanctum:prune-expired', 'cr7:prune-sessions'] as $command) {
            $event = $events->first(fn ($event) => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, "{$command} is not on the schedule");
            $this->assertNotSame(1440, $event->expiresAt, "{$command} would hold a killed pass's lock for a day");
        }
    }
}
