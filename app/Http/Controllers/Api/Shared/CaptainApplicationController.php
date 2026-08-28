<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreCaptainApplicationRequest;
use App\Models\CaptainApplication;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * APP-03 / APP-04 — POST /captain-applications, public.
 *
 * This creates an application, not an account (D-45): `users.type` stays the only
 * authorization truth and captain accounts are Admin-created. The applicant gets back
 * the reference `APP-04` prints and the review window, and nothing else.
 */
class CaptainApplicationController extends Controller
{
    use HandlesUploads;

    public function store(StoreCaptainApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $cityId = $data['city_id'] ?? null;
        $cityText = $data['city'] ?? null;

        // The app sends the city as the label it drew. Resolve it if it names a real
        // city, and keep the raw text either way so an unmatched one is not lost.
        if ($cityId === null && $cityText !== null) {
            $cityId = City::query()
                ->where('name_ar', $cityText)
                ->orWhere('name_en', $cityText)
                ->value('id');
        }

        $payload = [
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'city_id' => $cityId,
            'city_text' => $cityText,
            'vehicle_type' => $data['vehicle_type'] ?? $data['vehicle'] ?? 'motorcycle',
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ];

        // Licence and ID scans are personal documents — private disk, like driver
        // documents, so they never gain a public URL.
        $payload = $this->withPrivateUpload($payload, $request, 'licence_file', 'captain-applications');
        $payload = $this->withPrivateUpload($payload, $request, 'national_id_file', 'captain-applications');

        $application = DB::transaction(function () use ($payload) {
            $payload['reference'] = CaptainApplication::nextReference();

            return CaptainApplication::create($payload);
        });

        return ApiResponse::success([
            'reference' => $application->reference,
            'review_hours' => (int) Settings::get('driver_applications.review_hours', 48),
            'status' => $application->status,
        ], message: 'تم استلام طلبك بنجاح', status: 201);
    }
}
