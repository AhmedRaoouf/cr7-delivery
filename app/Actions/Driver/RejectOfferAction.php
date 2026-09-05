<?php

namespace App\Actions\Driver;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Support\DispatchService;
use Illuminate\Support\Facades\DB;

/** doc 05 §5 — rejection excludes the driver from every future round; if it was the last open offer, start the next round immediately. */
class RejectOfferAction
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(Driver $driver, int $orderId, string $reasonCode): void
    {
        $attempt = $driver->driverAttempts()->where('order_id', $orderId)->where('result', 'sent')->first();

        if (! $attempt) {
            throw new ApiException('not_eligible', 'هذا العرض غير متاح لك', 403);
        }

        DB::transaction(function () use ($driver, $attempt, $reasonCode) {
            $attempt->update(['result' => 'rejected', 'reason_code' => $reasonCode, 'responded_at' => now()]);
            $driver->increment('rejected_offers');
        });

        $this->dispatch->advanceIfRoundExhausted($attempt->order()->lockForUpdate()->first());
    }
}
