<?php

namespace App\Console\Commands;

use App\Models\StaticPage;
use App\Support\Legal\LegalContent;
use Illuminate\Console\Command;

/**
 * Batch 72 — write the in-app `terms` and `privacy` pages from {@see LegalContent}.
 *
 * `GET /pages/{slug}` reads the `static_pages` table, which `ContentSeeder` filled once; a
 * server that was seeded before this batch keeps the old, shorter text until something
 * rewrites it. This does exactly that for the two pages the public web pages also show, and
 * touches nothing else — not `about`, not the FAQ, which `db:seed --class=ContentSeeder`
 * would also rewrite.
 *
 * It overwrites those two rows, so an edit an admin made on `ADM` in between is replaced.
 */
class SyncLegalPagesCommand extends Command
{
    protected $signature = 'content:sync-legal';

    protected $description = 'Write the in-app terms and privacy pages from the text the public /terms and /privacy pages show';

    public function handle(): int
    {
        foreach (LegalContent::inAppPages() as $page) {
            $row = StaticPage::query()->updateOrCreate(['slug' => $page['slug']], $page + ['is_active' => true]);

            $this->line(sprintf('%s: %s', $page['slug'], $row->wasRecentlyCreated ? 'created' : ($row->wasChanged() ? 'updated' : 'already current')));
        }

        return self::SUCCESS;
    }
}
