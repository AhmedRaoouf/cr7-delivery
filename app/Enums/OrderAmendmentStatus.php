<?php

namespace App\Enums;

/** doc 99 §4, D-172 — the life of a change a place proposes to a `pending` order. */
enum OrderAmendmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
