<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET /admin/audit-logs. Global-scope admins only. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('user')
            ->when($request->integer('filter.user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->string('filter.action')->isNotEmpty(), fn ($q) => $q->where('action', $request->string('filter.action')))
            ->when($request->date('filter.date_from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('filter.date_to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::success($logs->items(), meta: [
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'last_page' => $logs->lastPage(),
        ]);
    }
}
