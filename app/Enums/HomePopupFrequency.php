<?php

namespace App\Enums;

/** How often one customer meets the same home popup. */
enum HomePopupFrequency: string
{
    /** Every time the home screen opens. For the one week an offer is the whole point. */
    case Always = 'always';

    /** The default — once in a calendar day, so a returning customer is not worn down. */
    case OncePerDay = 'once_per_day';

    /** Once ever. A welcome card, a change of terms, a new city announcement. */
    case Once = 'once';
}
