<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case VodafoneCash = 'vodafone_cash';
    case Instapay = 'instapay';
}
