<?php

namespace App\Enums;

/** doc 03 §3 / FR-LOC-05 — selected per city by Admin. */
enum CityPricingType: string
{
    case FixedZone = 'fixed_zone';
    case DistanceBased = 'distance_based';
}
