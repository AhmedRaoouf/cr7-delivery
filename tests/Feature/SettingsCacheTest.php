<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_nobody_wrote_is_looked_up_once_not_on_every_read(): void
    {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach (range(1, 5) as $_) {
            $this->assertSame('fallback', Settings::get('nobody.wrote.this', 'fallback'));
        }

        $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `settings`'))->count();
        $this->assertSame(1, $reads);
    }

    public function test_writing_a_key_that_was_remembered_as_missing_is_seen_at_once(): void
    {
        Cache::flush();
        $this->assertNull(Settings::get('late.key'));

        Settings::set('late.key', '0');

        $this->assertSame('0', Settings::get('late.key'));
        $this->assertSame(1, Setting::query()->where('key', 'late.key')->count());
    }
}
