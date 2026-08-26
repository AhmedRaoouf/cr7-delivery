<?php

namespace App\Enums;

enum ComplaintPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /** Default resolution window in hours (doc 03 §9 `support.resolution_hours.*`). */
    public function defaultResolutionHours(): int
    {
        return match ($this) {
            self::High => 4,
            self::Normal => 24,
            self::Low => 72,
        };
    }
}
