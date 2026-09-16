<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\Setting;
use App\Support\DeliveryCommission;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * D-70 / D-71 — the operator's real numbers, replacing the launch placeholders.
 *
 * `SettingsSeeder` uses `firstOrCreate`, which is right for a default and useless for a
 * correction: once `payment.vodafone_cash_number` held `01000000000`, re-seeding could
 * never move it. This seeder **overwrites**, but only where the stored value is still a
 * known placeholder — an operator who has since typed their own number in `ADM-46` keeps
 * it.
 *
 * Numbers are from the company flyer, 2026-09-05: 01099450110 and 01095958686.
 * Both are Vodafone prefixes (010), which is why the wallet number is one of them.
 *
 * `payment.instapay_handle` holds the same primary number, on the operator's instruction
 * of 2026-09-05: «ممكن تعمل إنستا بي برضه أي رقم من اللي فوق دول حالياً لحد ما نشوف». An
 * Egyptian mobile number **is** a valid InstaPay address, so this is a real destination
 * rather than a stand-in — but it is the wallet number, not a dedicated handle. Replace it
 * from `ADM-46` the moment a real one exists.
 */
class OperatorSettingsSeeder extends Seeder
{
    private const PRIMARY_PHONE = '01099450110';

    private const SECONDARY_PHONE = '01095958686';

    /**
     * Values that mean «nobody has set this yet» and may be overwritten.
     *
     * 🔴 This list used to hold `''` and `'0'` as well, and {@see replacePlaceholder}
     * compares `(string) $current` — so a deliberate zero, and a field an operator had
     * deliberately cleared, both matched and were silently reverted by the next
     * `db:seed`. A placeholder has to be a value nobody would ever *choose*; zero and
     * blank are choices. `'null'` stays because it is the four-character string a bad
     * hand-edit leaves behind, not the null value.
     */
    private const PLACEHOLDERS = ['01000000000', 'cr7delivery@instapay', 'null'];

    /** What `driver.default_earning_*` held before D-70 — the only values it may replace. */
    private const PRE_D70_EARNING_TYPE = ['fixed'];

    private const PRE_D70_EARNING_VALUE = ['15', '15.0', '15.00'];

    /** @var list<string> every key this run touched, so only those are re-read. */
    private array $touched = [];

    public function run(): void
    {
        // New keys — the split, in one place, for every captain.
        $this->put(DeliveryCommission::SETTING, 15, 'delivery');

        $this->put('support.phone_alt', self::SECONDARY_PHONE, 'support');
        $this->put('payment.vodafone_cash_number_alt', self::SECONDARY_PHONE, 'payment');
        // doc 13 §4 — a number inside an Arabic sentence is an LTR run. The first
        // seeding of this line shipped a bare `24`, so the un-isolated string is
        // itself treated as a placeholder and corrected on the next run.
        $this->replaceKnown('support.hours_note', 'متواجدون لخدمتكم ‪24‬ ساعة', 'support', ['متواجدون لخدمتكم 24 ساعة']);

        // Corrections — applied only over a placeholder.
        $this->replacePlaceholder('support.phone', self::PRIMARY_PHONE, 'support');
        $this->replacePlaceholder('support.whatsapp', self::PRIMARY_PHONE, 'support');
        $this->replacePlaceholder('payment.vodafone_cash_number', self::PRIMARY_PHONE, 'payment');
        $this->replacePlaceholder('payment.instapay_handle', self::PRIMARY_PHONE, 'payment');

        // D-70 — the standard captain rule. `earning_value = 0` is not «no earnings»:
        // it means «the standard split», and {@see \App\Support\DriverEarningCalculator}
        // reads the rate from the setting above. Keeping the percentage out of the driver
        // row is what lets the operator move 15 → 20 in one place instead of once per
        // captain and then wonder why the numbers stopped adding up.
        //
        // 🔴 These two were `force()`d unconditionally, so every `db:seed` — and the
        // release routine runs one — threw away whatever the operator had set and put
        // D-70's defaults back, with nothing said. They are a **migration off the
        // pre-D-70 values**, not a standing instruction.
        $this->migrateEarningDefaultsOffPreD70();

        // Existing captains still on the old seeded default (`fixed` at 15.00) move onto
        // it. Anyone the operator deliberately put on another rule is left alone.
        Driver::query()
            ->where('earning_type', 'fixed')
            ->where('earning_value', 15.00)
            ->update(['earning_type' => 'percent_of_delivery_fee', 'earning_value' => 0]);

        // Only the keys this run actually wrote. `Cache::flush()` emptied the whole store
        // — every zone lookup, every schedule mutex, and on a cache-backed session driver
        // every signed-in session — to publish at most eight rows.
        foreach (array_unique($this->touched) as $key) {
            Settings::forget($key);
        }
    }

    /**
     * D-70's captain rule, written only over the rule it replaces.
     *
     * The pair is judged **together**, and that is the whole point: `fixed` on its own is
     * a rule an operator may legitimately choose, so a per-key «is it still `fixed`?» can
     * never tell the pre-D-70 default apart from a deliberate one and would keep
     * overwriting it for ever. `fixed` **at ‪15‬** is the old default and nothing else.
     */
    private function migrateEarningDefaultsOffPreD70(): void
    {
        $type = Setting::query()->where('key', 'driver.default_earning_type')->first();
        $value = Setting::query()->where('key', 'driver.default_earning_value')->first();

        $isPreD70 = fn (?Setting $row, array $superseded) => $row === null
            || ($row->value !== null && is_scalar($row->value) && in_array((string) $row->value, $superseded, true));

        if (! $isPreD70($type, self::PRE_D70_EARNING_TYPE) || ! $isPreD70($value, self::PRE_D70_EARNING_VALUE)) {
            return;
        }

        $this->force('driver.default_earning_type', 'percent_of_delivery_fee', 'driver');
        $this->force('driver.default_earning_value', 0, 'driver');
    }

    private function put(string $key, mixed $value, string $group): void
    {
        $setting = Setting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);

        if ($setting->wasRecentlyCreated) {
            $this->touched[] = $key;
        }
    }

    private function force(string $key, mixed $value, string $group): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        $this->touched[] = $key;
    }

    private function replacePlaceholder(string $key, mixed $value, string $group): void
    {
        $this->replaceKnown($key, $value, $group, []);
    }

    /**
     * Writes only over a value nobody chose: no row at all, a known placeholder, or one
     * of `$superseded` — a value a previous run of this seeder wrote.
     *
     * 🔴 The absence of a row and a row holding `null` used to be the same answer here,
     * because `->value('value')` returns `null` for both. `support.phone`,
     * `support.whatsapp`, `payment.vodafone_cash_number` and `payment.instapay_handle`
     * are all nullable, so an operator who deliberately **cleared** one on `ADM-46` had
     * the flyer's number put back on the next seed. The row is fetched now, and a row
     * that exists is the operator's answer whatever it holds.
     *
     * @param  list<string>  $superseded
     */
    private function replaceKnown(string $key, mixed $value, string $group, array $superseded): void
    {
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null) {
            $this->force($key, $value, $group);

            return;
        }

        $current = $setting->value;

        // `null` on an existing row is a deliberate blank and is left alone. A scalar is
        // compared as text so `15`, `"15"` and `15.00` all match one superseded entry.
        if ($current !== null && is_scalar($current)
            && (in_array((string) $current, self::PLACEHOLDERS, true)
                || in_array((string) $current, $superseded, true))) {
            $this->force($key, $value, $group);
        }
    }
}
