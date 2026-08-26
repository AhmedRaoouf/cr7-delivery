<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/** doc 08 §3 — DELETE /auth/account: soft delete + PII anonymization. */
class DeleteAccountAction
{
    public function execute(User $user, ?string $reason): void
    {
        $anonymousTag = 'deleted_'.$user->id.'_'.Str::random(6);

        $user->forceFill([
            'name' => 'مستخدم محذوف',
            'phone' => $anonymousTag,
            'email' => $user->email ? $anonymousTag.'@deleted.local' : null,
            'avatar' => null,
            'is_active' => false,
        ])->save();

        $user->tokens()->delete();
        $user->delete();
    }
}
