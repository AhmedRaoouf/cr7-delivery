<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\Setting;
use App\Support\DeliveryCommission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

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
 * ⚠️ `payment.instapay_handle` is deliberately **not** set here. The flyer prints no
 * InstaPay address and inventing one would send customers' money to a handle that does
 * not exist. It stays at its placeholder until the operator gives the real one.
 */
class OperatorSettingsSeeder extends Seeder
{
    private const PRIMARY_PHONE = '01099450110';

    private const SECONDARY_PHONE = '01095958686';

    /** Values that mean «nobody has set this yet» and may be overwritten. */
    private const PLACEHOLDERS = ['01000000000', '', '0', 'null'];

    public function run(): void
    {
        // New keys — the split, in one place, for every captain.
        $this->put(DeliveryCommission::SETTING, 15, 'delivery');

        $this->put('support.phone_alt', self::SECONDARY_PHONE, 'support');
        $this->put('payment.vodafone_cash_number_alt', self::SECONDARY_PHONE, 'payment');
        $this->put('support.hours_note', 'متواجدون لخدمتكم 24 ساعة', 'support');

        // Corrections — applied only over a placeholder.
        $this->replacePlaceholder('support.phone', self::PRIMARY_PHONE, 'support');
        $this->replacePlaceholder('support.whatsapp', self::PRIMARY_PHONE, 'support');
        $this->replacePlaceholder('payment.vodafone_cash_number', self::PRIMARY_PHONE, 'payment');

        // D-70 — the standard captain rule. `earning_value = 0` is not «no earnings»:
        // it means «the standard split», and {@see \App\Support\DriverEarningCalculator}
        // reads the rate from the setting above. Keeping the percentage out of the driver
        // row is what lets the operator move 15 → 20 in one place instead of once per
        // captain and then wonder why the numbers stopped adding up.
        $this->force('driver.default_earning_type', 'percent_of_delivery_fee', 'driver');
        $this->force('driver.default_earning_value', 0, 'driver');

        // Existing captains still on the old seeded default (`fixed` at 15.00) move onto
        // it. Anyone the operator deliberately put on another rule is left alone.
        Driver::query()
            ->where('earning_type', 'fixed')
            ->where('earning_value', 15.00)
            ->update(['earning_type' => 'percent_of_delivery_fee', 'earning_value' => 0]);

        Cache::flush();
    }

    private function put(string $key, mixed $value, string $group): void
    {
        Setting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }

    private function force(string $key, mixed $value, string $group): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }

    private function replacePlaceholder(string $key, mixed $value, string $group): void
    {
        $current = Setting::query()->where('key', $key)->value('value');

        if ($current === null || in_array((string) $current, self::PLACEHOLDERS, true)) {
            $this->force($key, $value, $group);
        }
    }
}
