<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreDocumentRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — GET/POST /driver/documents: upload/replace, always re-enters review as pending. */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        return ApiResponse::success($driver->documents()->latest()->get());
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $document = $driver->documents()->updateOrCreate(
            ['type' => $request->string('type')->value()],
            [
                'file' => $request->string('file')->value(),
                'expires_at' => $request->date('expires_at'),
                'status' => 'pending',
                'note' => null,
            ],
        );

        return ApiResponse::success($document, 'تم رفع المستند بنجاح، جارٍ المراجعة', status: 201);
    }
}
