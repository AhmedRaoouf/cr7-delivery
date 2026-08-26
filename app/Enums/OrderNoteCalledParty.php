<?php

namespace App\Enums;

enum OrderNoteCalledParty: string
{
    case Customer = 'customer';
    case Restaurant = 'restaurant';
    case Driver = 'driver';
}
