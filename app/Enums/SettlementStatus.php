<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Paid = 'paid';
}
