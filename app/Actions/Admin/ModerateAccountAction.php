<?php

namespace App\Actions\Admin;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\TempPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * D-73 — what an operator can do to an account, and what each one actually means.
 *
 * Operator, 2026-09-08: «لو الأدمن عمل حظر للأكونت… ممكن يعمله بلوكينج، ممكن يعمله حذف
 * خالص، ممكن يعمله إعادة تعيين اللي هو كل البيانات بتاعته تتمسح ويخش من جديد».
 *
 * Four verbs, deliberately different in how much they destroy:
 *
 * | Verb | The account | The history | Reversible |
 * |---|---|---|---|
 * | `block` | cannot sign in, every token revoked | untouched | yes — `unblock` |
 * | `unblock` | works again | untouched | — |
 * | `reset` | signs in with a new temporary password, profile cleared | untouched | no |
 * | `delete` | soft-deleted, phone freed for re-registration | untouched | by hand, in the database |
 *
 * **Nothing here hard-deletes.** `users` is soft-deleted and every order, complaint,
 * settlement and audit row points at it. A `forceDelete` would either cascade the
 * platform's own financial history away or fail on a foreign key, and the operator's
 * «حذف خالص» means «this person is gone from the app», not «the money they moved never
 * happened». The phone number is released so the same person can start again, which is
 * the part that actually has to work.
 *
 * The **only** account this refuses is the last active super admin — the one lockout
 * nobody can undo from inside the product.
 */
class ModerateAccountAction
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function block(User $admin, User $target, string $reason): User
    {
        $this->assertActionable($admin, $target, 'إيقاف');

        // Told **before** the tokens go, or the push has no account to reach.
        $this->tell($target, 'account.blocked', ['reason' => $reason]);

        DB::transaction(function () use ($admin, $target, $reason) {
            $target->update([
                'is_active' => false,
                'blocked_at' => now(),
                'blocked_reason' => $reason,
                'blocked_by' => $admin->id,
            ]);

            if ($target->type === UserType::Admin) {
                $target->adminProfile()->update([
                    'suspended_at' => now(),
                    'suspended_by' => $admin->id,
                    'suspend_reason' => $reason,
                ]);
            }

            // A captain on shift must not stay on the dispatch pool's shortlist — going
            // offline does that, and the suspended account (`users.is_active`) keeps them
            // off it. `is_available` is not written: it used to be set false here and never
            // restored by `unblock()`, so an unblocked captain was invisible to dispatch
            // for good. It means «carries nothing» now, derived by `DriverTrip` (doc 63 §7).
            $target->driver?->update(['is_online' => false]);

            $target->tokens()->delete();
            // The phone stops receiving this platform's pushes the moment it stops
            // being this platform's user.
            $target->deviceTokens()->delete();
        });

        AuditLogger::record($admin, 'users.suspend', $target, ['reason' => $reason]);

        return $target->fresh();
    }

    public function unblock(User $admin, User $target): User
    {
        DB::transaction(function () use ($target) {
            $target->update([
                'is_active' => true,
                'blocked_at' => null,
                'blocked_reason' => null,
                'blocked_by' => null,
            ]);

            if ($target->type === UserType::Admin) {
                $target->adminProfile()->update([
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'suspend_reason' => null,
                ]);
            }
        });

        AuditLogger::record($admin, 'users.restore', $target);
        $this->tell($target, 'account.restored');

        return $target->fresh();
    }

    /**
     * «إعادة تعيين» — the profile is cleared and a new temporary password issued.
     *
     * What is **not** cleared: orders, complaints, ratings, settlements, the driver's
     * completed count. Those are the platform's records of what happened, not the
     * person's profile, and wiping them would silently rewrite reports that have
     * already been read. The person starts again; the ledger does not.
     *
     * @return string the temporary password to read out
     */
    public function reset(User $admin, User $target): string
    {
        $this->assertActionable($admin, $target, 'إعادة تعيين');

        $password = TempPassword::issue();

        DB::transaction(function () use ($target, $password) {
            $target->update([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'avatar' => null,
                'email' => null,
                'gender' => null,
                'birth_date' => null,
                'default_address_id' => null,
                'is_active' => true,
                'blocked_at' => null,
                'blocked_reason' => null,
                'blocked_by' => null,
            ]);

            $target->addresses()->delete();
            $target->tokens()->delete();
            $target->deviceTokens()->delete();
        });

        AuditLogger::record($admin, 'users.manage', $target, ['action' => 'reset']);
        $this->tell($target, 'account.reset');

        return $password;
    }

    public function delete(User $admin, User $target): void
    {
        $this->assertActionable($admin, $target, 'حذف');

        DB::transaction(function () use ($target) {
            // Freed so the same person can register again. The row is soft-deleted and
            // the unique index still covers it, so without this the number is burned.
            $target->update([
                'is_active' => false,
                'blocked_at' => now(),
                'phone' => 'deleted:'.$target->id.':'.$target->phone,
                'email' => null,
            ]);

            $target->tokens()->delete();
            $target->deviceTokens()->delete();
            $target->delete();
        });

        AuditLogger::record($admin, 'users.manage', $target, ['action' => 'delete']);
    }

    /**
     * The two refusals, and they are both about locking the platform out of itself.
     */
    private function assertActionable(User $admin, User $target, string $verb): void
    {
        if ($admin->id === $target->id) {
            throw new ApiException('cannot_moderate_self', "لا يمكنك {$verb} حسابك الخاص", 422);
        }

        if ($target->type !== UserType::Admin || ! $target->hasRole('super_admin')) {
            return;
        }

        $remaining = User::query()
            ->where('type', UserType::Admin)
            ->where('is_active', true)
            ->whereKeyNot($target->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->count();

        if ($remaining === 0) {
            throw new ApiException('last_super_admin', 'لا يمكن إيقاف آخر حساب super_admin', 422);
        }
    }

    /** @param array<string, string> $context */
    private function tell(User $target, string $type, array $context = []): void
    {
        try {
            $this->notifications->send($target, $type, $context);
        } catch (\Throwable $e) {
            // Moderation must land whether or not the person could be reached.
            Log::warning('moderation notice failed', ['user_id' => $target->id, 'error' => $e->getMessage()]);
        }
    }
}
