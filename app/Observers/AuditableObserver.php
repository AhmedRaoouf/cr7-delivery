<?php

namespace App\Observers;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * D-52 / doc 12 §7.5 — «every admin action on a row writes an `audit_logs` row».
 *
 * Sixteen admin controllers mutate rows and none of them said so. Writing the call by
 * hand in every `store`, `update` and `destroy` is about fifty edits that a reviewer has
 * to check one at a time, and the fifty-first endpoint — the one nobody has written yet —
 * silently opts out. The model event cannot be opted out of, so that is the hook, exactly
 * as `OrderObserver` argues for the nineteen call sites that write `status`.
 *
 * **Only an admin actor is recorded.** A restaurant editing its own menu goes through the
 * same `Product` model, and D-52 is about the platform's staff acting on other people's
 * rows, not about a tenant using their own dashboard. Console runs — seeders, migrations,
 * scheduled jobs — have no actor at all and write nothing.
 *
 * What this observer cannot see, and is therefore still recorded by hand:
 *
 * - **Pivot writes.** `syncPermissions()` writes `role_has_permissions`, not `roles`, so
 *   granting a permission fires no model event on `Role`. That is the single most
 *   security-relevant admin action there is, so `RoleController` records it explicitly.
 * - **Writes to another model.** «reset this restaurant's password» updates a `User`;
 *   `User` is not registered here because `UserController` already audits it, and a
 *   second observer row would double every entry that controller writes.
 */
class AuditableObserver
{
    /**
     * Never copied into an audit row.
     *
     * A hash is still a credential — an offline attack on `audit_logs` is cheaper than one
     * on `users`, and the log is read by more people than the table it describes. The
     * remaining two are token material.
     */
    private const REDACTED = ['password', 'remember_token', 'api_token', 'two_factor_secret'];

    /** Noise: every update changes `updated_at`, and nothing is learned from logging it. */
    private const IGNORED = ['updated_at', 'created_at'];

    public function created(Model $model): void
    {
        $this->write($model, 'created', $this->clean($model->getAttributes()), []);
    }

    public function updated(Model $model): void
    {
        $new = $this->clean($model->getChanges());

        // An update that only touched `updated_at` — a `touch()`, a no-op save — is not an
        // action anybody needs to read about later.
        if ($new === []) {
            return;
        }

        $this->write($model, 'updated', $new, $this->clean(array_intersect_key($model->getOriginal(), $new)));
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', [], $this->clean($model->getAttributes()));
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored', $this->clean($model->getAttributes()), []);
    }

    /**
     * The row itself.
     *
     * Written inline rather than after the commit: an admin action that rolls back did not
     * happen, and an audit trail that records it is worse than one that does not.
     */
    private function write(Model $model, string $verb, array $new, array $old): void
    {
        $admin = $this->admin();

        if ($admin === null) {
            return;
        }

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => $model->getTable().'.'.$verb,
            // The raw FQCN, matching AuditLogger: the enforced morph map only covers the
            // app's own polymorphic relations, and audit_logs must hold any model.
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /**
     * The acting admin, or null for a tenant, a customer, a queue worker or artisan.
     *
     * `request()->user()` and not `auth()->user()`. Every route in this API runs on
     * `auth:sanctum`, and the bare helper resolves the **default** guard — `web` — which
     * holds nobody on a token request. It returns null for every real call, so the
     * observer would write nothing and look like it was working.
     *
     * There is deliberately no `runningInConsole()` escape hatch. It reads as the right
     * way to keep seeders and scheduled jobs out of the trail, and it is: they have no
     * authenticated admin, so this returns null for them anyway. What it also excludes is
     * the whole test suite, which runs in the console — and an audit rule that cannot be
     * tested is an audit rule nobody will notice breaking.
     */
    private function admin(): ?User
    {
        $user = request()?->user() ?? auth('sanctum')->user();

        return $user instanceof User && $user->type === UserType::Admin ? $user : null;
    }

    private function clean(array $values): array
    {
        return array_diff_key($values, array_flip([...self::REDACTED, ...self::IGNORED]));
    }
}
