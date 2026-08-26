<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case InApp = 'in_app';
}
