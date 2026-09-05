<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverDocumentRequest;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 12 §5 — driver documents are "Admin only": stored on the private disk, never a public URL. */
class DriverDocumentController extends Controller
{
    use HandlesUploads;

    public function index(Driver $driver): JsonResponse
    {
        return ApiResponse::success($driver->documents()->latest()->get());
    }

    public function store(StoreDriverDocumentRequest $request, Driver $driver): JsonResponse
    {
        $data = $this->withPrivateUpload($request->validated(), $request, 'file', 'driver-documents');
        $document = $driver->documents()->create($data);

        return ApiResponse::success($document, 'تم رفع المستند بنجاح', status: 201);
    }

    /** doc 12 §5 — the only way to see a driver document: never a public URL. */
    public function file(DriverDocument $driverDocument): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($driverDocument->file), 404);

        return Storage::disk('local')->response($driverDocument->file);
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
