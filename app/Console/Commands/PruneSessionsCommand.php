<?php

namespace App\Console\Commands;

use App\Support\Auth\Sessions;
use Illuminate\Console\Command;

/**
 * D-120 — the refresh-token half of token pruning.
 *
 * `sanctum:prune-expired` removes access tokens whose `expires_at` has passed; it knows
 * nothing about refresh tokens, and nothing about a legacy token whose idle window has
 * closed, because neither has an `expires_at` it could read. This does both, and scrubs
 * the successor pairs held for the grace window once the window is over. What each one
 * means is in {@see Sessions::prune()}.
 *
 * Safe to run by hand at any time: everything it deletes can no longer authenticate.
 */
class PruneSessionsCommand extends Command
{
    protected $signature = 'cr7:prune-sessions';

    protected $description = 'Delete dead refresh tokens and idle legacy tokens, and scrub stale grace pairs (D-120)';

    public function handle(): int
    {
        $pruned = Sessions::prune();

        $this->line("grace pairs scrubbed     {$pruned['scrubbed']}");
        $this->line("refresh tokens deleted   {$pruned['refresh_tokens']}");
        $this->line("legacy tokens deleted    {$pruned['legacy_tokens']}");

        return self::SUCCESS;
    }
}
