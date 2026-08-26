<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §7 — GET /admin/permissions: grouped by the doc 12 §3 headings, with an ar/en label per string. */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $grouped = Permission::query()->orderBy('group')->orderBy('name')->get()->groupBy('group')->map(
            fn ($permissions) => $permissions->map(fn ($p) => [
                'name' => $p->name,
                'label_ar' => $p->label_ar,
                'label_en' => $p->label_en,
            ]),
        );

        return ApiResponse::success($grouped);
    }
}
