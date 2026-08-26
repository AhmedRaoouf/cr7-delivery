<?php

namespace App\Enums;

enum DeliveryIssueReportedBy: string
{
    case Driver = 'driver';
    case Customer = 'customer';
    case Restaurant = 'restaurant';
    case System = 'system';
}
