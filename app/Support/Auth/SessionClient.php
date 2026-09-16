<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * D-120 — who is asking for a session, read once from the request that asked.
 *
 * `refreshable` is the capability header. A client that sends `X-Auth-Refresh: 1` on a
 * sign-in knows how to renew a session and is given one that renews; a client that does
 * not — the 1.0.3 and 1.0.4 apps in the field, a dashboard built before this — is given
 * exactly the token it has always been given. The server cannot tell an old client from
 * a new one any other way, and guessing wrong in either direction signs somebody out:
 * an old app handed a one-hour token has no way to renew it.
 *
 * The rest is written on the refresh token for the operator's benefit — which binary,
 * which phone, from where — and decides nothing.
 */
final class SessionClient
{
    public const HEADER = 'X-Auth-Refresh';

    public function __construct(
        public readonly bool $refreshable = false,
        public readonly ?string $app = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            refreshable: filter_var($request->header(self::HEADER), FILTER_VALIDATE_BOOLEAN),
            app: self::clip($request->header('X-App'), 20),
            deviceId: self::clip($request->header('X-Device-Id'), 255),
            ip: $request->ip(),
            userAgent: self::clip($request->userAgent(), 255),
        );
    }

    /** A header is whatever the caller typed; the columns are not. */
    private static function clip(?string $value, int $length): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
