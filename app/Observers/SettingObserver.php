<?php

namespace App\Observers;

use App\Models\Setting;
use App\Support\Settings;

/**
 * batch 65 — the memo is busted by the **row**, not by whoever happened to write it.
 *
 * {@see Settings::get} caches with `rememberForever`, and the only two things that ever
 * cleared an entry were `Settings::set()` and the explicit `Settings::forget()` in
 * `SettingController::update()`. Every other way a row can change — a seeder, `tinker`, a
 * console command, a future controller that saves a `Setting` model directly — left every
 * `SettingsCatalog::*` reader on the old value **for the life of the process**, while every
 * raw `Setting::query()` reader in the same process saw the new one. One row, two answers,
 * and nothing on any screen to explain it.
 *
 * Hanging it off the model's own events makes «the row changed» and «the memo is stale»
 * the same event. The explicit `forget()` in the controller stays — it costs nothing and
 * it is the line that says out loud why this matters — but correctness no longer depends
 * on a caller remembering.
 *
 * The one path this cannot see is a query-builder write (`Setting::query()->update(...)`
 * or raw SQL), which fires no model event. There are none in `app/`, and
 * {@see \Tests\Feature\SettingsPanelCoverageTest} would not catch one, so if a bulk
 * settings update is ever added it must call {@see Settings::forget} itself.
 */
class SettingObserver
{
    public function saved(Setting $setting): void
    {
        $this->forget($setting);
    }

    public function deleted(Setting $setting): void
    {
        $this->forget($setting);
    }

    private function forget(Setting $setting): void
    {
        // The key as it was **before** a rename, too: renaming a row leaves the old
        // entry behind, and the old key is exactly what the stale readers are asking for.
        $original = $setting->getOriginal('key');

        if (is_string($original) && $original !== '') {
            Settings::forget($original);
        }

        if (is_string($setting->key) && $setting->key !== '') {
            Settings::forget($setting->key);
        }
    }
}
