<?php

namespace App\Enums;

enum DriverDocumentType: string
{
    /** The front of the card — the name and the number. */
    case NationalId = 'national_id';

    /** The back — the address and the expiry. Both sides or neither is a document. */
    case NationalIdBack = 'national_id_back';

    case License = 'license';
    case VehicleLicense = 'vehicle_license';
    case CriminalRecord = 'criminal_record';
    case Other = 'other';
}
