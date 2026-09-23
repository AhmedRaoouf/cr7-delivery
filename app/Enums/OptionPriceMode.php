<?php

namespace App\Enums;

/**
 * doc 78 — what an option's price means.
 *
 * `add` — the option is an extra: its price is added to the dish («جبنة إضافية +10»).
 * `final` — the option *is* the dish at that size: its price is the price of one unit
 * («نص كيلو = 500», not 950 + 500). A product has at most one `final` group, and it is
 * always a single choice.
 */
enum OptionPriceMode: string
{
    case Add = 'add';
    case Final = 'final';
}
