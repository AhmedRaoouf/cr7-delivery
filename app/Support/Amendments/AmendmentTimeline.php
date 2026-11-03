<?php

namespace App\Support\Amendments;

use Illuminate\Support\Collection;

/**
 * doc 99 D-172 — an amendment writes history rows that do not move the status
 * (`pending → pending`, `amendment_proposed` …). The office and the place read them; the
 * customer's timeline is a list of statuses, and a 1.0.23 app would draw «بانتظار المكان»
 * twice. So the customer's two timelines leave them out — and only them.
 */
final class AmendmentTimeline
{
    public const REASONS = ['amendment_proposed', 'amendment_accepted', 'amendment_rejected', 'amendment_expired', 'amendment_withdrawn'];

    public static function withoutAmendmentRows(Collection $history): Collection
    {
        return $history->reject(fn ($row) => in_array($row->reason_code, self::REASONS, true) && $row->from_status === $row->to_status);
    }
}
