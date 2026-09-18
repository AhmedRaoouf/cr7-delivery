<?php

namespace App\Console\Commands;

use App\Models\OtpBlock;
use App\Models\OtpCode;
use App\Models\SmsSend;
use Illuminate\Console\Command;

/**
 * Batch 68 — the verification tables are written on every code the platform answers, so
 * they are pruned like the session tables.
 *
 *   * `sms_sends` older than ‪30‬ days. The longest window any cap counts over is ‪24‬ hours;
 *     the rest is the month `ADM-46`'s usage page and an operator's questions can look back
 *     over.
 *   * `otp_codes` two days past their expiry whose registration token (if any) has also
 *     run out. Nothing can verify against them.
 *   * `otp_blocks` that ended more than ‪90‬ days ago — kept that long so «who was blocked,
 *     and who let them out» can still be answered.
 *
 * Safe to run by hand at any time: nothing it deletes is counted by any live rule.
 */
class PruneOtpCommand extends Command
{
    public const LEDGER_DAYS = 30;

    public const BLOCK_DAYS = 90;

    protected $signature = 'cr7:prune-otp';

    protected $description = 'Delete old SMS ledger rows, dead verification codes and long-ended number blocks (batch 68)';

    public function handle(): int
    {
        $ledger = SmsSend::query()->where('created_at', '<', now()->subDays(self::LEDGER_DAYS))->delete();

        $codes = OtpCode::query()
            ->where('expires_at', '<', now()->subDays(2))
            ->where(fn ($q) => $q->whereNull('follow_up_expires_at')->orWhere('follow_up_expires_at', '<', now()))
            ->delete();

        $blocks = OtpBlock::query()
            ->where(fn ($q) => $q->where('blocked_until', '<', now()->subDays(self::BLOCK_DAYS))
                ->orWhere('lifted_at', '<', now()->subDays(self::BLOCK_DAYS)))
            ->delete();

        $this->line("sms ledger rows deleted  {$ledger}");
        $this->line("dead codes deleted       {$codes}");
        $this->line("ended blocks deleted     {$blocks}");

        return self::SUCCESS;
    }
}
