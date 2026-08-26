<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminUserRequest extends FormRequest
{
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
            'scope.scope_type' => ['nullable', 'in:global,city,zone'],
            'scope.city_ids' => ['nullable', 'array'],
            'scope.zone_ids' => ['nullable', 'array'],
        ];
    }
}
