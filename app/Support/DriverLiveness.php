<?php

namespace App\Support;

/**
 * D-56 — a captain counts as live while the last ping is younger than this.
 *
 * One number, one reader. It was five: `DispatchService`, `ErrandDispatcher`,
 * `OrderParties` and `OrderController` each queried it themselves and each carried its own
 * literal `90` — and `DispatchService` also consulted a **second key**,
 * `driver.location_stale_seconds`, which `SettingsSeeder` writes as ‪60‬ and which is on no
 * panel at all. So on a deployment whose `dispatch.*` row was missing, dispatch called a
 * captain stale at ‪60‬ seconds while the fleet map and the order screen called the same
 * captain live until ‪90‬ — and the operator had nowhere to look, because the key deciding
 * it does not exist as far as `ADM-46` and `ADM-47` are concerned.
 *
 * The catalogue key wins. The legacy key is still honoured when the catalogue key has no
 * row, so an operator who edited the old one years ago is not overruled by a default — but
 * it can no longer quietly beat a value that is on the screen, and the last resort is the
 * catalogue's declared default rather than a fourth copy of the number.
 */
final class DriverLiveness
{
    public const SETTING = 'dispatch.location_stale_seconds';

    /** Not on any panel. Seeded once, readable, and never allowed to win a contest. */
    public const LEGACY_SETTING = 'driver.location_stale_seconds';

    public static function staleSeconds(): int
    {
        if (Settings::get(self::SETTING) !== null) {
            return SettingsCatalog::int(self::SETTING);
        }

        $legacy = Settings::get(self::LEGACY_SETTING);

        return is_numeric($legacy)
            ? (int) $legacy
            : (int) SettingsCatalog::default(self::SETTING);
    }

    /**
     * The same answer from a `pluck` a caller already has in hand.
     *
     * `DispatchService` reads its settings once per round rather than once per captain,
     * and that is worth keeping — but the **order of precedence** must not be a second
     * implementation, so the rule lives here and only the lookup differs.
     *
     * @param  \Illuminate\Support\Collection<string, mixed>  $settings
     */
    public static function staleSecondsFrom($settings): int
    {
        $stored = $settings[self::SETTING] ?? null;

        if ($stored !== null) {
            return is_numeric($stored) ? (int) $stored : (int) SettingsCatalog::default(self::SETTING);
        }

        $legacy = $settings[self::LEGACY_SETTING] ?? null;

        return is_numeric($legacy)
            ? (int) $legacy
            : (int) SettingsCatalog::default(self::SETTING);
    }

    /** The two keys a caller should pluck to use {@see staleSecondsFrom}. */
    public static function keys(): array
    {
        return [self::SETTING, self::LEGACY_SETTING];
    }
}
