<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Batch 68 — the database cache store never deletes a row it is not asked for again.
 *
 * `DatabaseStore` forgets an expired entry only when that exact key is read, so a key that
 * is never read again — a response cached under a catalogue version that has since been
 * replaced, a geocoded point nobody drops a pin on twice — stays in `cache` for ever. The
 * public read cache (ADMIN-LISTS.md §5) makes such keys on purpose: invalidation is a new
 * version, not a delete. This removes every row past its expiry, in bounded batches so a
 * pass never holds a lock for long on shared hosting.
 *
 * A no-op when the store is anything but `database`. Safe to run by hand at any time:
 * nothing it deletes can be read.
 */
class PruneExpiredCacheCommand extends Command
{
    protected $signature = 'cr7:prune-cache {--batch=2000 : rows deleted per statement}';

    protected $description = 'Delete expired rows from the database cache table (batch 68)';

    public function handle(): int
    {
        $store = config('cache.default');

        if (config("cache.stores.{$store}.driver") !== 'database') {
            $this->line("store «{$store}» is not the database — nothing to prune");

            return self::SUCCESS;
        }

        $connection = config("cache.stores.{$store}.connection");
        $table = (string) config("cache.stores.{$store}.table", 'cache');
        $batch = max(100, (int) $this->option('batch'));
        $now = now()->getTimestamp();
        $deleted = 0;

        do {
            $gone = DB::connection($connection)->table($table)
                ->where('expiration', '<', $now)
                ->limit($batch)
                ->delete();
            $deleted += $gone;
        } while ($gone === $batch);

        $this->line("expired cache rows deleted   {$deleted}");

        return self::SUCCESS;
    }
}
