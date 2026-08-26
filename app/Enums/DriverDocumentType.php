<?php

namespace App\Enums;

enum DriverDocumentType: string
{
    case NationalId = 'national_id';
    case License = 'license';
    case VehicleLicense = 'vehicle_license';
    case CriminalRecord = 'criminal_record';
    case Other = 'other';
}
