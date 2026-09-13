<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Resources\Customer\AddressResource;
use App\Models\UserAddress;
use App\Support\AddressContactPhone;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — addresses are owned by the caller (doc 12 §5): user_id = auth.id, always. */
class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // `user` rides along because `AddressResource` resolves the phone the
        // captain will call from the address's **owner** — without it that is
        // one lazy load per row.
        $addresses = $request->user()->addresses()->with(['city', 'serviceZone', 'user'])->latest()->get();

        return ApiResponse::success(AddressResource::collection($addresses));
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $data = $this->withContactPhone($request, $this->mapZone($request->validated()));

        $address = DB::transaction(function () use ($request, $data) {
            $address = $request->user()->addresses()->create($data);
            $this->syncDefault($request, $address);

            return $address;
        });

        return ApiResponse::success($this->resource($address), 'تم إضافة العنوان بنجاح', status: 201);
    }

    public function update(UpdateAddressRequest $request, UserAddress $address): JsonResponse
    {
        $this->ensureOwned($request, $address);

        $data = $this->withContactPhone($request, $this->mapZone($request->validated()));

        DB::transaction(function () use ($request, $address, $data) {
            $address->update($data);
            $this->syncDefault($request, $address);
        });

        return ApiResponse::success($this->resource($address), 'تم تحديث العنوان بنجاح');
    }

    /**
     * Deleting the default hands the default on.
     *
     * `users.default_address_id` kept pointing at the soft-deleted row, so the basket
     * priced the next order off an address the customer had just thrown away, and the
     * app opened checkout on it. It now falls to the most recently added address that
     * is left — or to nothing, when none is.
     */
    public function destroy(Request $request, UserAddress $address): JsonResponse
    {
        $this->ensureOwned($request, $address);

        DB::transaction(function () use ($request, $address) {
            $user = $request->user();
            $wasDefault = $address->is_default || (int) $user->default_address_id === (int) $address->id;

            $address->forceFill(['is_default' => false])->save();
            $address->delete();

            if (! $wasDefault) {
                return;
            }

            $next = $user->addresses()->latest('id')->first();
            $next?->forceFill(['is_default' => true])->save();
            $user->forceFill(['default_address_id' => $next?->id])->save();
        });

        return ApiResponse::success(message: 'تم حذف العنوان بنجاح');
    }

    public function makeDefault(Request $request, UserAddress $address): JsonResponse
    {
        $this->ensureOwned($request, $address);

        DB::transaction(function () use ($request, $address) {
            $address->update(['is_default' => true]);
            $this->syncDefault($request, $address, force: true);
        });

        return ApiResponse::success($this->resource($address), 'تم تعيين العنوان كافتراضي');
    }

    /**
     * D-81/D-83 — the row plus the two names and the fee the client would
     * otherwise have to look up, refetched so the write's own changes and the
     * sibling `is_default` clear are both in it.
     */
    private function resource(UserAddress $address): AddressResource
    {
        return new AddressResource($address->fresh()->load(['city', 'serviceZone', 'user']));
    }

    /**
     * D-112 — the account's number is stored as null; another number only once the panel
     * allows it and, if it asks, the account has proven it by SMS.
     */
    private function withContactPhone(Request $request, array $data): array
    {
        if (array_key_exists('contact_phone', $data)) {
            $data['contact_phone'] = AddressContactPhone::resolve($request->user(), $data['contact_phone']);
        }

        return $data;
    }

    private function mapZone(array $data): array
    {
        if (array_key_exists('zone_id', $data)) {
            $data['service_zone_id'] = $data['zone_id'];
            unset($data['zone_id']);
        }

        return $data;
    }

    private function syncDefault(Request $request, UserAddress $address, bool $force = false): void
    {
        if (! $force && ! $address->is_default) {
            return;
        }

        $request->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
        $request->user()->forceFill(['default_address_id' => $address->id])->save();
    }

    private function ensureOwned(Request $request, UserAddress $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 404);
    }
}
