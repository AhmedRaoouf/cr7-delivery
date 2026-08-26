<?php

namespace App\Enums;

/** doc 05 — one row per driver per dispatch round. */
enum OrderDriverAttemptResult: string
{
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case CancelledAfterAccept = 'cancelled_after_accept';
    case ReassignedByAdmin = 'reassigned_by_admin';
    case UnassignedByAdmin = 'unassigned_by_admin';
}
