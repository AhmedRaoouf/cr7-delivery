<?php

namespace App\Enums;

enum OrderNoteCallOutcome: string
{
    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case WrongNumber = 'wrong_number';
    case SwitchedOff = 'switched_off';
}
