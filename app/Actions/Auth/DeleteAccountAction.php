<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Auth\AccountErasure;
use App\Support\FileUploader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * doc 08 §3 — `DELETE /auth/account`: a customer's own deletion.
 *
 * One transaction: the user row is locked, the active-work check is repeated under the lock
 * (`409 active_order_blocks_deletion`), the personal data is erased by {@see AccountErasure},
 * and the row is anonymised and soft-deleted **last**. The files (profile photo, complaint
 * photos) are deleted only after the commit, so an erasure that rolled back has touched no
 * file. Calling it again for an account that is already deleted does nothing.
 *
 * Self-service is for customers. A captain carries a wallet the office has to settle and a
 * restaurant is a business, so both ask in writing (`403 account_deletion_by_request`); the
 * public «حذف الحساب» page explains how.
 */
class DeleteAccountAction
{
    public function execute(User $user, ?string $reason): void
    {
        if ($user->type !== UserType::Customer) {
            throw new ApiException(
                'account_deletion_by_request',
                'يتم حذف حسابات الكباتن والمطاعم بطلب مكتوب إلى الدعم — راجع صفحة حذف الحساب.',
                403,
            );
        }

        if ($user->trashed()) {
            return;
        }

        $files = DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                return [];
            }

            AccountErasure::assertNoActiveWork($locked);

            $files = AccountErasure::erase($locked);
            $anonymousTag = 'deleted_'.$locked->id.'_'.Str::random(6);

            $locked->forceFill([
                'name' => AccountErasure::DELETED_NAME,
                'phone' => $anonymousTag,
                'email' => $locked->email ? $anonymousTag.'@deleted.local' : null,
                'avatar' => null,
                'gender' => null,
                'birth_date' => null,
                'default_address_id' => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,
                // Nobody can sign in to it, and now nobody holds a password that could.
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'is_active' => false,
            ])->save();

            $locked->delete();

            return $files;
        });

        // The photos go once the erasure is certain.
        foreach ($files as $url) {
            FileUploader::deletePublic($url, holders: 0);
        }

        // The caller's instance still shows the old name; `refresh()` reads the row past its soft delete.
        $user->refresh();
    }
}
