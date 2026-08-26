<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case Reset = 'reset';
    case Verify = 'verify';
}
