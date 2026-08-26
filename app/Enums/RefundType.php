<?php

namespace App\Enums;

enum RefundType: string
{
    case Full = 'full';
    case Partial = 'partial';
}
