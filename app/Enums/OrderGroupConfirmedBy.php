<?php

namespace App\Enums;

enum OrderGroupConfirmedBy: string
{
    case Customer = 'customer';
    case System = 'system';
    case Admin = 'admin';
}
