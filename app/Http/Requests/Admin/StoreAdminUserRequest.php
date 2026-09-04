<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdminUserRequest extends FormRequest
{
    use NormalisesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields();
    }

    public function authorize(): bool
    {
        return $this->user()->can('users.invite');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', 'unique:users,phone'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'exists:roles,name'],
            'grants' => ['nullable', 'array'],
            'grants.*' => ['string', 'exists:permissions,name'],
            'scope' => ['nullable', 'array'],
            // `type` as well as `scope_type`, because `POST /auth/login` publishes the
            // scope as `{"type": …}` and a client that echoes back what it was given must
            // not be told it sent nothing — which, before this, minted a global admin.
            'scope.scope_type' => ['nullable', 'in:global,city,zone'],
            'scope.type' => ['nullable', 'in:global,city,zone'],
            'scope.city_ids' => ['nullable', 'array'],
            'scope.city_ids.*' => ['integer', 'exists:cities,id'],
            'scope.zone_ids' => ['nullable', 'array'],
            'scope.zone_ids.*' => ['integer', 'exists:service_zones,id'],
        ];
    }
}
