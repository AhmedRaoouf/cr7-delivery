<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** doc 12 §7.5 / doc 16 D-50 — every admin mutation writes an audit_logs row with before/after values. */
class AuditLogger
{
    public static function record(User $admin, string $action, Model $subject, array $newValues = [], array $oldValues = []): void
    {
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            // Deliberately the raw FQCN, not getMorphClass(): the enforced morph map
            // (AppServiceProvider) only covers the app's own polymorphic relations
            // (rateable/favoritable), and audit_logs must be able to log any model.
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
