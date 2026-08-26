<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminProfile extends Model
{
    protected $fillable = [
        'user_id', 'job_title', 'avatar_path', 'two_factor_enabled', 'two_factor_secret',
        'two_factor_recovery_codes', 'two_factor_confirmed_at', 'invited_by', 'invited_at',
        'accepted_at', 'last_login_at', 'last_login_ip', 'must_change_password',
        'suspended_at', 'suspended_by', 'suspend_reason',
    ];

    protected $hidden = ['two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
