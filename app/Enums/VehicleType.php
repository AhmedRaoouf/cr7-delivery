<?php

namespace App\Enums;

enum VehicleType: string
{
    case Motorcycle = 'motorcycle';
    case Bicycle = 'bicycle';
    case Car = 'car';
    case Scooter = 'scooter';

    /**
     * The word an Arabic reader sees. Same four strings the app prints for
     * `driver.vehicle.*`, so a captain's vehicle reads the same on the phone
     * and in the operator's exported workbook.
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::Motorcycle => 'دراجة نارية',
            self::Bicycle => 'دراجة',
            self::Car => 'سيارة',
            self::Scooter => 'سكوتر',
        };
    }
}
