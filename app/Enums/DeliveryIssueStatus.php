<?php

namespace App\Enums;

enum DeliveryIssueStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}
