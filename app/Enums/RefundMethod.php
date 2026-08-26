<?php

namespace App\Enums;

enum RefundMethod: string
{
    case Cash = 'cash';
    case VodafoneCash = 'vodafone_cash';
    case Instapay = 'instapay';
    case Wallet = 'wallet';
}
