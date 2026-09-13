<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case Reset = 'reset';
    case Verify = 'verify';

    /**
     * D-112 — a second number on an address, proven by the customer who typed it. It
     * never opens a session and never creates an account: it only lets that number be
     * saved on the customer's own addresses.
     */
    case AddressPhone = 'address_phone';

    /** D-107 — a signed-in account changing its own password. Always a real SMS. */
    case PasswordChange = 'password_change';
}
