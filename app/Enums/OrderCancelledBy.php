<?php

namespace App\Enums;

enum OrderCancelledBy: string
{
    case Customer = 'customer';
    case Restaurant = 'restaurant';
    case Driver = 'driver';
    case Admin = 'admin';
    case System = 'system';
}
