<?php

namespace App\Models;

use App\Support\OtpGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 68 — one verification code the platform answered, and what it cost.
 *
 * The ledger every OTP cap is counted from ({@see OtpGuard}). Written for
 * every send the platform says yes to — a real SMS, the operator's test code, and the
 * shadow send a stranger's «نسيت كلمة المرور» gets — and never for a refusal, so a
 * blocked caller hammering the endpoint adds nothing to anybody's count.
 *
 * @property string $phone
 * @property string $purpose
 * @property string $kind real|test|shadow|review
 */
class SmsSend extends Model
{
    public const KIND_REAL = 'real';

    public const KIND_TEST = 'test';

    public const KIND_SHADOW = 'shadow';

    /** A store reviewer's number, answered the fixed review code — see ReviewAccess. */
    public const KIND_REVIEW = 'review';

    public const UPDATED_AT = null;

    protected $fillable = [
        'phone', 'purpose', 'kind', 'ip', 'device_id', 'burned_at', 'settled_at', 'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'burned_at' => 'datetime',
            'settled_at' => 'datetime',
            'cleared_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
