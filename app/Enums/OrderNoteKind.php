<?php

namespace App\Enums;

enum OrderNoteKind: string
{
    case Note = 'note';
    case Call = 'call';
}
