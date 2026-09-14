<?php

namespace App\Http\Requests\Shared;

use App\Enums\DeviceApp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'platform' => ['required', 'in:android,ios,web'],
            // `DeviceApp`, not the `X-App` header's values. The column is
            // `enum('app', ['customer','driver','restaurant','admin'])` and the mobile
            // binary can only ever send `customer` or `driver` (D-45: one app, two
            // shells), so `in:app,restaurant,admin` rejected every registration the
            // phone made with a 422 — and would have written a value the column itself
            // refuses if one had got through. No customer and no captain has ever had a
            // device token stored, which is every push notification on both shells.
            'app' => ['required', Rule::enum(DeviceApp::class)],
            'device_id' => ['required', 'string'],
            // D-100 — what this build can do. A build that sends nothing is the one before
            // the ring, and is sent the ordinary push ({@see \App\Models\DeviceToken::rings()}).
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'in:ring'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}(\+\d+)?$/'],
        ];
    }
}
