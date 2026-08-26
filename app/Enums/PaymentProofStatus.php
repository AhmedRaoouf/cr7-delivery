<?php

namespace App\Enums;

enum PaymentProofStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
