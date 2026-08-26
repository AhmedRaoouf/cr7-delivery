<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type,
            'avatar' => $this->avatar,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'must_change_password' => $this->must_change_password,
            'phone_verified_at' => $this->phone_verified_at,
            'default_address_id' => $this->default_address_id,
        ];
    }
}
