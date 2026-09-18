<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverDocumentRequest;
use App\Http\Resources\Admin\DriverDocumentResource;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 12 §5 — driver documents are "Admin only": stored on the private disk, never a public URL. */
class DriverDocumentController extends Controller
{
    use HandlesUploads;

    public function index(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureDriverInScope($request, $driver);

        return ApiResponse::success(DriverDocumentResource::collection($driver->documents()->latest()->get()));
    }

    public function store(StoreDriverDocumentRequest $request, Driver $driver): JsonResponse
    {
        $this->ensureDriverInScope($request, $driver);

        $data = $this->withPrivateUpload($request->validated(), $request, 'file', 'driver-documents');
        $document = $driver->documents()->create($data);

        return ApiResponse::success(new DriverDocumentResource($document), 'تم رفع المستند بنجاح', status: 201);
    }

    /**
     * doc 12 §5 — the only way to see a driver document: never a public URL.
     *
     * Operator, 2026-09-10: «امكانيه تنزل المستندات المرفوعه (صور البطاقه الشخصيه)
     * ورؤيتها كصور في الداشبورد». Both, off one route, because they are the same bytes
     * and only the header differs:
     *
     * - **inline** (the default) is what an `<img src>` needs. A browser handed
     *   `attachment` on an image tag renders nothing at all and logs no error — the
     *   picture is simply absent, which is exactly how this card came to be a list of
     *   filenames.
     * - **`?download=1`** is `attachment`, with a filename an operator can find again.
     *   `national_id.jpg` in a folder of thirty downloads is useless, so the name
     *   carries the captain and the kind: `captain-17-national_id.jpg`.
     *
     * The extension comes off the stored path rather than from the client: the name is
     * echoed back into a `Content-Disposition` header, and a name taken from a request
     * is a header-injection hole.
     */
    public function file(Request $request, DriverDocument $driverDocument): StreamedResponse
    {
        $this->ensureDocumentInScope($request, $driverDocument);

        abort_unless(Storage::disk('local')->exists($driverDocument->file), 404);

        if (! $request->boolean('download')) {
            return Storage::disk('local')->response($driverDocument->file);
        }

        $extension = pathinfo($driverDocument->file, PATHINFO_EXTENSION);
        $type = $driverDocument->type instanceof \BackedEnum
            ? $driverDocument->type->value
            : (string) $driverDocument->type;

        $name = 'captain-'.$driverDocument->driver_id.'-'.($type !== '' ? $type : 'document')
            .($extension === '' ? '' : '.'.$extension);

        return Storage::disk('local')->download($driverDocument->file, $name);
    }

    public function review(Request $request, DriverDocument $driverDocument): JsonResponse
    {
        $this->ensureDocumentInScope($request, $driverDocument);

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,rejected'],
        ]);

        $driverDocument->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? null,
        ]);

        return ApiResponse::success(new DriverDocumentResource($driverDocument), 'تم تحديث حالة المستند بنجاح');
    }

    /** Batch 67 — a captain's documents follow the captain's scope (D-52). */
    private function ensureDriverInScope(Request $request, Driver $driver): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToDrivers(Driver::query(), $request->user()), $driver);
    }

    private function ensureDocumentInScope(Request $request, DriverDocument $document): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToDriverDocuments(DriverDocument::query(), $request->user()), $document);
    }
}
