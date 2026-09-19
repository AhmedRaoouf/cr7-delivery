<?php

namespace App\Console\Commands;

use App\Support\Media\MediaLibrarySync;
use App\Support\Media\ShippedAssets;
use Illuminate\Console\Command;

/**
 * `php artisan media:sync` — rebuild the system media from its registry (doc 71 §7).
 *
 * Idempotent. It never adds a shipped picture to the library — that is the Admin's
 * choice; `--list-available` only prints what is waiting to be chosen.
 */
class MediaSyncCommand extends Command
{
    protected $signature = 'media:sync {--list-available : Also print the shipped pictures not in the library}';

    protected $description = 'Rebuild the protected system media from database/data/media-system.json';

    public function handle(): int
    {
        $result = MediaLibrarySync::run();

        $this->info("system media: {$result['created']} created, {$result['refreshed']} refreshed");

        foreach ($result['missing'] as $key) {
            $this->error("missing file for system asset [{$key}]");
        }

        if ($this->option('list-available')) {
            $available = ShippedAssets::available();
            $this->line($available->count().' shipped pictures available');
            $available->each(fn ($item, $key) => $this->line("  {$key}"));
        }

        return $result['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
