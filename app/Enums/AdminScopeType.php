<?php

namespace App\Enums;

/** doc 24 §1 — a permission answers "what", a scope answers "which rows". */
enum AdminScopeType: string
{
    case Global = 'global';
    case City = 'city';
    case Zone = 'zone';
}
