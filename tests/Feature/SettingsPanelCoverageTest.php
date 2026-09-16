<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Setting;
use App\Models\User;
use App\Support\DriverLiveness;
use App\Support\Settings;
use App\Support\SettingsCatalog;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * batch 65 — «اعدادات الادمن بايظه تقريبا مش بتغير شوف كل حاجه واكد عليها».
 *
 * `SettingsPanelTest` pins the write path on a handful of keys. This one asks the three
 * questions of **every** declared key, because «شوف كل حاجه» is the whole request:
 *
 *  1. **Is it editable from the panel?** Every key is PATCHed with a value that differs
 *     from the one it holds, and the schema the panel redraws itself from must come back
 *     carrying the new one.
 *  2. **Does it take effect without a restart?** The value is read back through
 *     {@see SettingsCatalog::get} **after** it was read once before the edit, so a key
 *     whose memo the controller forgot to bust fails here rather than in production.
 *  3. **Is it read by anything?** A key that no runtime code consults is a switch that
 *     documents behaviour the platform does not have. The catalogue is checked against
 *     the source tree, and a new orphan fails this test by name.
 *
 * The operator's own symptom is pinned separately, at the bottom: a fee moved to ‪5‬ and
 * back to ‪0‬ must reach `GET /config` both times. Zero is a value, not an absence.
 */
class SettingsPanelCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Keys whose only consumer is a client, through `GET /config`.
     *
     * Nothing in PHP reads them; the phone and the two dashboards do. Every entry needs
     * the `/config` path that carries it, and the last test here proves each one is
     * actually published — so this list cannot quietly become a place to hide an orphan.
     *
     * @var array<string, string>
     */
    private const PUBLISHED_TO_CLIENTS = [];

    /**
     * A value each key is moved to, where the obvious «something else» would be refused.
     *
     * Every entry is a cross-field rule from {@see SettingsCatalog::validate} — the pair
     * has to stay sane, so the test cannot just pick the minimum.
     *
     * @var array<string, mixed>
     */
    private const OVERRIDES = [
        // Must stay below `payment.transfer_deadline_minutes`, which is ‪15‬.
        'payment.reminder_before_minutes' => 9,
        // Must stay above `payment.reminder_before_minutes`, which is ‪5‬.
        'payment.transfer_deadline_minutes' => 30,
        // Must stay below `restaurant.prep_minutes_max`, which is ‪120‬.
        'restaurant.prep_minutes_min' => 8,
        // Must stay above `restaurant.prep_minutes_min`, which is ‪5‬.
        'restaurant.prep_minutes_max' => 90,
        // A wallet that is on needs a number, so the number is moved rather than cleared
        // and the switch is left on.
        'payment.vodafone_cash_enabled' => true,
        'payment.instapay_enabled' => true,
        // At least one method must stay on; cash is the one the others lean on.
        'payment.cash_enabled' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    private function asAdmin(): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    // ------------------------------------------------- 1 + 2 · editable, and live

    /**
     * Every declared key, one PATCH each: written, redrawn, and read back live.
     *
     * One key at a time on purpose. `SettingsCatalog::validate` is all-or-nothing, so a
     * single batch would hide which key was refused behind one 422 — and a per-key loop
     * names the offender in its own failure message.
     */
    public function test_every_declared_key_is_editable_and_takes_effect_at_once(): void
    {
        $fields = SettingsCatalog::fields();
        $this->assertGreaterThan(60, count($fields), 'the catalogue lost most of its keys');

        foreach ($fields as $key => $field) {
            $current = SettingsCatalog::get($key);
            $next = self::OVERRIDES[$key] ?? $this->otherValue($field, $current);

            // Read once before the edit so the `rememberForever` memo is warm. Without
            // this the assertion below would pass on a controller that never forgets.
            SettingsCatalog::get($key);

            $response = $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [$key => $next]]);

            $this->assertSame(
                200,
                $response->status(),
                "{$key} could not be saved from the panel: ".json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE),
            );

            // (a) editable — the row holds it.
            $this->assertEquals($next, SettingsCatalog::get($key), "{$key} did not store the value it was sent");

            // (b) live — read through the cached accessor after a read that warmed it.
            $this->assertEquals($next, SettingsCatalog::get($key), "{$key} is still served from a stale memo after a save");

            // (c) redrawn — the panel that re-renders from the response agrees.
            $redrawn = collect($response->json('data'))
                ->flatMap(fn (array $group) => $group['fields'])
                ->firstWhere('key', $key);

            $this->assertNotNull($redrawn, "{$key} vanished from the schema the save answered with");
            $this->assertEquals($next, $redrawn['value'], "{$key} redraws with a different value than it stored");

            // Unchanged where it was already right, so the next key starts from a sane
            // pair rather than from whatever the last one left behind.
            if (! array_key_exists($key, self::OVERRIDES)) {
                $this->assertNotEquals($current, $next, "{$key} was 'changed' to the value it already held");
            }
        }
    }

    /**
     * A nullable field can be **cleared**, and a cleared field stays cleared.
     *
     * `''` and `null` are the same gesture once a form has serialised itself, so both
     * arrive here, and both have to mean «empty» rather than «refused».
     */
    public function test_a_nullable_field_can_be_cleared_with_either_empty_or_null(): void
    {
        foreach ([['support.hours_note', ''], ['support.phone_alt', null]] as [$key, $sent]) {
            $this->asAdmin()
                ->patchJson('/api/v1/admin/settings', ['settings' => [$key => $sent]])
                ->assertOk();

            $this->assertNull(SettingsCatalog::get($key), "{$key} could not be cleared");
        }
    }

    /**
     * A non-nullable field emptied is refused with a sentence that says what to do.
     *
     * Both panels now catch this before it is sent (doc 65 §3), so this pins the server's
     * half: the refusal is per key, nothing else in the same submit is written, and the
     * message is the one the operator reads.
     */
    public function test_an_emptied_required_field_is_refused_by_name_and_writes_nothing(): void
    {
        $before = SettingsCatalog::int('ring.max_attempts');

        $response = $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => [
                'platform.order_fee' => '',
                'ring.max_attempts' => 5,
            ]])
            ->assertStatus(422);

        $this->assertSame('هذه القيمة مطلوبة', $response->json('errors')['platform.order_fee'][0]);
        $this->assertSame($before, SettingsCatalog::int('ring.max_attempts'), 'the valid half of a refused submit was written');
    }

    // ------------------------------------------------------- 3 · read by something

    /**
     * No key on the panel is read by nothing.
     *
     * The catalogue is the operator's contract: a switch on the screen says the platform
     * behaves this way. A key nothing consults is a promise with no code behind it, and
     * it is worse than a missing screen because it looks like it worked.
     */
    public function test_every_declared_key_is_read_by_runtime_code(): void
    {
        $sources = $this->sourceFiles();
        $orphans = [];

        foreach (array_keys(SettingsCatalog::fields()) as $key) {
            $found = false;

            foreach ($sources as $contents) {
                if (str_contains($contents, "'{$key}'") || str_contains($contents, "\"{$key}\"")) {
                    $found = true;
                    break;
                }
            }

            if (! $found && ! array_key_exists($key, self::PUBLISHED_TO_CLIENTS)) {
                $orphans[] = $key;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "these settings are on the panel and read by nothing:\n  ".implode("\n  ", $orphans)
                ."\nEither wire the key up, take it off the catalogue, or — if a client is its only reader"
                ." — name it in PUBLISHED_TO_CLIENTS with the `/config` path that carries it.",
        );
    }

    /** Every key excused as client-only really is published by `GET /config`. */
    public function test_a_key_excused_as_client_only_is_actually_published(): void
    {
        if (self::PUBLISHED_TO_CLIENTS === []) {
            $this->assertTrue(true, 'nothing is excused');

            return;
        }

        $config = $this->getJson('/api/v1/config')->assertOk()->json('data');

        foreach (self::PUBLISHED_TO_CLIENTS as $key => $path) {
            $this->assertTrue(
                data_get($config, $path, '__missing__') !== '__missing__',
                "{$key} is excused as client-only but `GET /config` does not carry `{$path}`",
            );
        }
    }

    /**
     * Every raw reader's literal fallback agrees with the catalogue's declared default.
     *
     * `CartFormatter` fell back to ‪3‬ for `cart.max_restaurants` where the catalogue, the
     * seeder and `BasketLimit` all say ‪2‬ — so a server that had never written the row
     * allowed a third place in the basket on one screen and refused it on every other.
     * The reader and the declaration are two copies of one number; this is the assertion
     * that they have not drifted apart again.
     */
    public function test_a_key_with_no_row_at_all_reads_its_declared_default_everywhere(): void
    {
        Setting::query()->delete();

        foreach (SettingsCatalog::fields() as $key => $field) {
            Settings::forget($key);
        }

        // `GET /config` is the one place every client reads these from, and it is built
        // from a `pluck` rather than from the catalogue — so it is the likeliest place
        // for a second copy of a default to survive.
        $config = $this->getJson('/api/v1/config')->assertOk()->json('data');

        $this->assertSame(SettingsCatalog::int('cart.max_restaurants'), $config['cart']['max_restaurants']);
        $this->assertSame(SettingsCatalog::int('cart.max_qty_per_line'), $config['cart']['max_qty_per_line']);
        $this->assertEquals(SettingsCatalog::float('platform.order_fee'), $config['platform']['order_fee']);
        $this->assertSame(SettingsCatalog::bool('ring.enabled'), $config['ring']['enabled']);
        $this->assertSame(SettingsCatalog::int('ring.duration_seconds'), $config['ring']['duration_seconds']);
        $this->assertSame(SettingsCatalog::bool('orders.pickup_code_required'), $config['orders']['pickup_code_required']);
        $this->assertSame(SettingsCatalog::bool('orders.delivery_code_required'), $config['orders']['delivery_code_required']);
        $this->assertSame(SettingsCatalog::get('app.force_update.android_min_version'), $config['force_update']['android_min']);
        $this->assertSame(SettingsCatalog::bool('app.maintenance_mode'), $config['maintenance']['enabled']);
        $this->assertSame(SettingsCatalog::bool('payment.cash_enabled'), $config['payment']['cash_enabled']);
        $this->assertSame(SettingsCatalog::int('payment.transfer_deadline_minutes'), $config['payment']['transfer_deadline_minutes']);
        $this->assertSame(SettingsCatalog::bool('auth.register_requires_otp'), $config['auth']['register_requires_otp']);
    }

    // --------------------------------------------- the operator's own symptom

    /**
     * «لما جيت اغير رسوم الخدمه مسمعتش كنت عاملها ‪5‬ ولما جيت احطها صفر مسمعتش».
     *
     * Both halves of that sentence, in order, against the response every client reads.
     * Zero is where a «falsy means unset» bug shows itself, so the trip back to ‪0‬ is the
     * half that matters.
     */
    public function test_the_service_fee_moves_to_five_and_back_to_zero_on_config(): void
    {
        $this->assertEquals(0.0, $this->getJson('/api/v1/config')->json('data.platform.order_fee'));

        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['platform.order_fee' => 5]])
            ->assertOk();

        $this->assertEquals(5.0, $this->getJson('/api/v1/config')->json('data.platform.order_fee'));

        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['platform.order_fee' => 0]])
            ->assertOk();

        $this->assertEquals(0.0, $this->getJson('/api/v1/config')->json('data.platform.order_fee'));
        $this->assertSame(0, Setting::query()->where('key', 'platform.order_fee')->value('value'));
    }

    /** A switch turned **off** is stored as off, not skipped for being falsy. */
    public function test_a_switch_turned_off_reaches_config_as_off(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['ring.enabled' => false]])
            ->assertOk();

        $this->assertFalse($this->getJson('/api/v1/config')->json('data.ring.enabled'));
        $this->assertFalse(SettingsCatalog::bool('ring.enabled'));
    }

    /**
     * A caller's fallback never becomes the cached answer for everyone else.
     *
     * `Settings::get` cached `$value ?? $default` — so on an unwritten key the whole
     * process was stuck with whichever reader warmed the entry first, and two readers
     * with different literals disagreed by boot order.
     */
    public function test_one_readers_fallback_is_not_served_to_another(): void
    {
        Setting::query()->where('key', 'platform.order_fee')->delete();
        Settings::forget('platform.order_fee');

        $this->assertSame(99, Settings::get('platform.order_fee', 99));
        $this->assertSame(7, Settings::get('platform.order_fee', 7));
        $this->assertNull(Settings::get('platform.order_fee'));
    }

    /**
     * A row written by anything at all clears its own memo.
     *
     * The `rememberForever` entry used to be busted from exactly two places, so a seeder,
     * a `tinker` edit or a console command left every `SettingsCatalog` reader on the old
     * value for the life of the process while every raw `Setting::query()` reader in the
     * same process saw the new one. One row, two answers, nothing on screen to explain it.
     */
    public function test_a_row_written_outside_the_controller_still_clears_its_memo(): void
    {
        // Warm the memo on the declared default — `ring.duration_seconds` is declared,
        // not seeded, so there is no row yet.
        $this->assertSame(30, SettingsCatalog::int('ring.duration_seconds'));

        // Created by a model save that is not the controller: the path a seeder takes.
        $row = Setting::query()->updateOrCreate(['key' => 'ring.duration_seconds'], ['value' => 45, 'group' => 'ring']);

        $this->assertSame(45, SettingsCatalog::int('ring.duration_seconds'), 'a created row was hidden behind the cached default');

        $row->update(['value' => 50]);

        $this->assertSame(50, SettingsCatalog::int('ring.duration_seconds'), 'an updated row was hidden behind its old value');

        $row->delete();

        $this->assertSame(30, SettingsCatalog::int('ring.duration_seconds'), 'a deleted row left its value cached');
    }

    /**
     * D-56 — one captain, one liveness verdict.
     *
     * `driver.location_stale_seconds` is seeded at ‪60‬ and is on no panel; the catalogue
     * key is ‪90‬. Dispatch consulted the invisible one and everything else the visible one,
     * so on a deployment missing the `dispatch.*` row a captain quiet for ‪70‬ seconds was
     * live on the fleet map and invisible to dispatch, with nowhere for the operator to
     * look. The panel's key wins whenever it has a row.
     */
    public function test_the_panels_liveness_number_beats_the_key_that_is_on_no_panel(): void
    {
        Setting::query()->updateOrCreate(['key' => 'driver.location_stale_seconds'], ['value' => 60, 'group' => 'driver']);

        $this->assertSame(90, DriverLiveness::staleSeconds());

        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['dispatch.location_stale_seconds' => 120]])
            ->assertOk();

        $this->assertSame(120, DriverLiveness::staleSeconds());

        // With no row on the panel's key at all, the legacy one an operator may have
        // edited years ago is still honoured rather than replaced by a default.
        // A model delete — a query-builder `delete()` fires no event, which is the one
        // path `SettingObserver` says out loud it cannot see.
        Setting::query()->where('key', 'dispatch.location_stale_seconds')->first()->delete();

        $this->assertSame(60, DriverLiveness::staleSeconds());
    }

    /**
     * A setting nothing enforces says so on the panel — and stops saying so the day
     * something does.
     *
     * `app.maintenance_mode` and both `app.force_update.*` keys are saved and published,
     * but no middleware answers the `503 maintenance` / `force_update` the app's blocker
     * screens wait for, and no client reads those `/config` blocks. The maintenance
     * warning promised «يوقف التطبيق لكل العملاء والكباتن». Both halves are asserted, so
     * whoever builds the enforcement is made to take the «لا يُطبَّق بعد» line off too.
     */
    public function test_a_setting_nothing_enforces_says_so_and_only_while_that_is_true(): void
    {
        $enforced = $this->sourceMentions(["'maintenance'", "'force_update'"], ['app/Http/Middleware', 'bootstrap']);

        foreach (['app.maintenance_mode', 'app.force_update.android_min_version', 'app.force_update.ios_min_version'] as $key) {
            $helper = (string) (SettingsCatalog::field($key)['helper_ar'] ?? '');
            $saysUnenforced = str_starts_with($helper, 'لا يُطبَّق بعد');

            if ($enforced) {
                $this->assertFalse($saysUnenforced, "{$key} is enforced now — take «لا يُطبَّق بعد» off its helper");
            } else {
                $this->assertTrue($saysUnenforced, "{$key} is enforced by nothing, and the panel must say so");
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Whether any PHP file under `$directories` contains one of `$needles`. */
    private function sourceMentions(array $needles, array $directories): bool
    {
        foreach ($directories as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Every PHP source the platform runs, less the catalogue's own declaration. */
    private function sourceFiles(): array
    {
        $root = base_path();
        $contents = [];

        foreach (['app', 'routes', 'config'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (str_ends_with($file->getPathname(), 'app/Support/SettingsCatalog.php')) {
                    continue;
                }

                $contents[] = (string) file_get_contents($file->getPathname());
            }
        }

        return $contents;
    }

    /**
     * A legal value for this field that is not the one it holds.
     *
     * Built from the declaration rather than from a table of literals, so a key added to
     * the catalogue tomorrow is covered by this test the day it lands.
     */
    private function otherValue(array $field, mixed $current): mixed
    {
        return match ($field['type']) {
            'bool' => ! (bool) $current,
            'int' => $this->otherNumber($field, $current, true),
            'decimal' => $this->otherNumber($field, $current, false),
            'phone' => $current === '01000000111' ? '01000000222' : '01000000111',
            'email' => $current === 'panel@cr7-delivery.com' ? 'panel2@cr7-delivery.com' : 'panel@cr7-delivery.com',
            'digits' => $current === '654321' ? '123456' : '654321',
            'version' => $current === '9.9.9' ? '8.8.8' : '9.9.9',
            'enum' => $this->otherChoice($field, $current),
            'multi' => $this->otherChoices($field, $current),
            default => $current === 'قيمة الاختبار' ? 'قيمة أخرى' : 'قيمة الاختبار',
        };
    }

    private function otherNumber(array $field, mixed $current, bool $integer): int|float
    {
        $min = (float) ($field['min'] ?? 0);
        $max = (float) ($field['max'] ?? 1000);
        $step = $integer ? 1.0 : (float) ($field['step'] ?? 1);

        // Walk up from the floor until something inside the range differs from what is
        // stored. A one-field range would have nowhere to go, and the catalogue has none.
        for ($candidate = $min; $candidate <= $max; $candidate += $step) {
            $value = $integer ? (int) round($candidate) : round($candidate, 2);

            if ((float) $value != (float) $current) {
                return $value;
            }
        }

        $this->fail("{$field['key']} has no second legal value between {$min} and {$max}");
    }

    private function otherChoice(array $field, mixed $current): string
    {
        foreach ($field['options'] ?? [] as $option) {
            if ($option['value'] !== $current) {
                return $option['value'];
            }
        }

        $this->fail("{$field['key']} offers only one choice");
    }

    /** @return list<string> */
    private function otherChoices(array $field, mixed $current): array
    {
        $all = array_column($field['options'] ?? [], 'value');
        $held = is_array($current) ? $current : [];

        return count($held) === count($all) ? array_slice($all, 0, 1) : $all;
    }
}
