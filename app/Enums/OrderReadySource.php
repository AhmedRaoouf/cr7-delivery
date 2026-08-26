<?php

namespace App\Enums;

/** doc 04 §10 — how a sub-order reached `ready`. */
enum OrderReadySource: string
{
    case Restaurant = 'restaurant';
    case Timer = 'timer';
    case Admin = 'admin';
}
