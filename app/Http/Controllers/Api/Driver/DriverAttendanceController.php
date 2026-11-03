<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Attendance;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 99 §2 — D-170: `GET /driver/attendance/today`, the shift card on `DRV-06/07`. */
class DriverAttendanceController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->with('user')->firstOrFail();
        $day = Attendance::day($driver, BusinessTime::date());

        return ApiResponse::success([
            'date' => $day['date'],
            'is_online' => $day['is_online'],
            'state' => $day['state'],
            'checked_in_at' => $day['checked_in_at'],
            'checked_in_in_coverage' => $day['checked_in_in_coverage'],
            'last_seen_at' => $day['last_seen_at'],
            'checked_out_at' => $day['checked_out_at'],
            'online_seconds' => $day['online_seconds'],
            'gap_seconds' => $day['gap_seconds'],
            'gaps_count' => $day['gaps_count'],
            'sessions' => $day['sessions'],
        ]);
    }
}
