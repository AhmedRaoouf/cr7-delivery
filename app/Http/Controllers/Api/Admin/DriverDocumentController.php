<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverDocumentRequest;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverDocumentController extends Controller
{
    public function index(Driver $driver): JsonResponse
    {
        return ApiResponse::success($driver->documents()->latest()->get());
    }

    public function store(StoreDriverDocumentRequest $request, Driver $driver): JsonResponse
    {
        $document = $driver->documents()->create($request->validated());

        return ApiResponse::success($document, 'تم رفع المستند بنجاح', status: 201);
    }

    public function review(Request $request, DriverDocument $driverDocument): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,rejected'],
        ]);

        $driverDocument->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? null,
        ]);

        return ApiResponse::success($driverDocument, 'تم تحديث حالة المستند بنجاح');
    }
}
