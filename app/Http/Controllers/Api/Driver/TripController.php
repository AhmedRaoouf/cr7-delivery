<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\DriverTrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 63 §3.3 — `GET /driver/trip`, what `DRV-37` draws: every order the captain carries
 * and every sibling they hold, the stops in the order to make them, the room left, and the
 * cash the trip needs.
 */
class TripController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        return ApiResponse::success(DriverTrip::present($driver));
    }
}
