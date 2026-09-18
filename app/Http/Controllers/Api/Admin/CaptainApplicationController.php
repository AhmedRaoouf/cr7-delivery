<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDriverAction;
use App\Enums\CaptainApplicationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewCaptainApplicationRequest;
use App\Models\CaptainApplication;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Lists\ListSearch;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADM-30..ADM-32 — the captain application queue. Approving one is what creates the
 * account: `APP-03` never does (D-45).
 *
 * Scope is the applicant's city (D-50), so a regional admin sees only their own queue
 * and an out-of-scope row resolves as 404, never 403.
 */
class CaptainApplicationController extends Controller
{
    use FiltersLists;

    public function __construct(private readonly CreateDriverAction $createDriver) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'status'], '-created_at');

        $query = $this->scoped($request)->with('city:id,name_ar,name_en');

        $this->whereInList($query, 'status', $params->enum('status', array_column(CaptainApplicationStatus::cases(), 'value')));
        $this->whereInList($query, 'city_id', $params->ids('city_id'));
        $this->whereInList($query, 'vehicle_type', $params->enum('vehicle_type', ['motorcycle', 'bicycle', 'car', 'scooter']));
        $this->whereBetweenDates($query, 'created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'names' => ['full_name'],
            'phones' => ['phone'],
            'refs' => ['reference'],
        ]);

        $applications = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated(
            $applications,
            array_map(fn (CaptainApplication $row) => $this->withFileUrls($row), $applications->items()),
            sort: $sort,
        );
    }

    /** The three private-disk paths, keyed by the segment `file()` accepts. */
    private const FILES = [
        'national_id' => 'national_id_file',
        'national_id_back' => 'national_id_back_file',
        'licence' => 'licence_file',
    ];

    public function show(Request $request, int $application): JsonResponse
    {
        $row = $this->scoped($request)->with(['city', 'reviewer:id,name', 'createdDriver'])->findOrFail($application);

        return ApiResponse::success($this->withFileUrls($row));
    }

    /**
     * The columns themselves stay `$hidden`: what the dashboard gets is a URL it can
     * fetch through the BFF, and only for the sides that were actually uploaded. An
     * absent key is «no photo», which is what the review screen has to say out loud
     * rather than rendering a broken image.
     *
     * On the list as well as the detail, because the review dialog opens from the list
     * and deciding on an applicant is looking at their card.
     */
    private function withFileUrls(CaptainApplication $row): array
    {
        return $row->toArray() + [
            // Cast, because an applicant who uploaded nothing leaves an empty PHP array
            // here and `json_encode` writes that as `[]`, not `{}` — and the dashboard
            // parses this field as an object. One applicant with no scans was enough to
            // throw inside the server render and take `ADM-30` down with a 500.
            'files' => (object) collect(self::FILES)
                ->filter(fn (string $column) => filled($row->getAttribute($column)))
                ->map(fn (string $column, string $kind) => "admin/captain-applications/{$row->id}/files/{$kind}")
                ->all(),
        ];
    }

    /**
     * doc 12 §5 — an identity document is Admin-only and never gets a public URL.
     *
     * Scoped like every other read here, so a regional admin cannot pull the ID card of
     * an applicant in a city they do not run by guessing an id.
     */
    public function file(Request $request, int $application, string $kind): StreamedResponse
    {
        abort_unless(array_key_exists($kind, self::FILES), 404);

        $row = $this->scoped($request)->findOrFail($application);
        $path = $row->getAttribute(self::FILES[$kind]);

        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        // `?download=1` saves it instead of drawing it — the same flag
        // `DriverDocumentController::file` carries, so the dashboard has one spelling to
        // remember. The filename is built here, never taken from the request: it is
        // echoed into a `Content-Disposition` header.
        if ($request->boolean('download')) {
            $extension = pathinfo((string) $path, PATHINFO_EXTENSION);

            return Storage::disk('local')->download(
                $path,
                "applicant-{$row->id}-{$kind}".($extension === '' ? '' : '.'.$extension),
            );
        }

        return Storage::disk('local')->response($path);
    }

    public function review(ReviewCaptainApplicationRequest $request, int $application): JsonResponse
    {
        $row = $this->scoped($request)->findOrFail($application);
        $admin = $request->user();
        $data = $request->validated();

        if (in_array($row->status, [CaptainApplicationStatus::Approved, CaptainApplicationStatus::Rejected], true)) {
            throw new ApiException('application_already_reviewed', 'تمت مراجعة هذا الطلب بالفعل', 422);
        }

        return DB::transaction(function () use ($row, $admin, $data) {
            $before = $row->only(['status', 'rejection_reason']);
            $tempPassword = null;

            if ($data['status'] === 'approved') {
                $created = $this->createDriver->execute([
                    'name' => $row->full_name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'city_id' => $data['city_id'] ?? $row->city_id,
                    'national_id' => $row->national_id,
                    'vehicle_type' => $row->vehicle_type->value,
                    'vehicle_plate' => $row->vehicle_plate,
                    'earning_type' => $data['earning_type'] ?? Settings::get('driver.default_earning_type', 'fixed'),
                    'earning_value' => $data['earning_value'] ?? Settings::get('driver.default_earning_value', 15.00),
                    'zone_ids' => $data['zone_ids'] ?? [],
                ]);

                $row->created_driver_id = $created['driver']->id;
                $tempPassword = $created['temp_password'];

                // The scans the applicant sent follow them onto the account. Without this
                // an approved captain's documents card was empty and the only copy of
                // their ID stayed on a row nobody opens again.
                foreach ([
                    'national_id' => $row->national_id_file,
                    'national_id_back' => $row->national_id_back_file,
                    'license' => $row->licence_file,
                ] as $type => $path) {
                    if (blank($path)) {
                        continue;
                    }

                    $created['driver']->documents()->create([
                        'type' => $type,
                        'file' => $path,
                        // Approving the application is the review. Filing them as
                        // `pending` would ask an admin to approve the same photo twice.
                        'status' => 'approved',
                    ]);
                }
            }

            $row->fill([
                'status' => $data['status'],
                'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            AuditLogger::record($admin, 'driver_applications.review', $row, $row->only(['status', 'rejection_reason']), $before);

            return ApiResponse::success([
                'application' => $row->fresh(['createdDriver']),
                // Shown once, on the admin's screen, so the captain can be given their
                // first sign-in. It is never stored and never re-readable.
                'temp_password' => $tempPassword,
            ], message: 'تم تحديث حالة الطلب');
        });
    }

    private function scoped(Request $request)
    {
        $query = CaptainApplication::query();
        $admin = $request->user();

        if (AdminScope::isGlobal($admin)) {
            return $query;
        }

        // An application with no resolved city is global-only: nobody regional owns it.
        return $query->whereIn('city_id', AdminScope::scopedCityIds($admin));
    }
}
