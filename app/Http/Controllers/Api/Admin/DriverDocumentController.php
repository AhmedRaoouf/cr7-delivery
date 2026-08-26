<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = DriverDocument::query()
            ->with('driver.user')
            ->when($request->integer('driver_id'), fn ($q, $driverId) => $q->where('driver_id', $driverId))
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($documents->items(), meta: [
            'current_page' => $documents->currentPage(),
            'per_page' => $documents->perPage(),
            'total' => $documents->total(),
            'last_page' => $documents->lastPage(),
        ]);
    }

    public function approve(DriverDocument $document): JsonResponse
    {
        $document->update(['status' => 'approved', 'note' => null]);

        return ApiResponse::success($document, 'تم اعتماد المستند بنجاح');
    }

    public function reject(Request $request, DriverDocument $document): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $document->update(['status' => 'rejected', 'note' => $validated['note']]);

        return ApiResponse::success($document, 'تم رفض المستند');
    }
}
