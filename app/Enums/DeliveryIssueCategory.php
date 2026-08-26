<?php

namespace App\Enums;

enum DeliveryIssueCategory: string
{
    case CustomerUnreachable = 'customer_unreachable';
    case WrongAddress = 'wrong_address';
    case Accident = 'accident';
    case FoodProblem = 'food_problem';
    case DriverUnresponsive = 'driver_unresponsive';
    case Payment = 'payment';
    case Handover = 'handover';
    case Other = 'other';
}
