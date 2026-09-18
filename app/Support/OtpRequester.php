<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Batch 68 — who is asking for a code: the address and the install every OTP cap
 * counts by.
 *
 * **The address.** `$request->ip()`, except for one caller. Both dashboards call this API
 * from their BFF — a Node server — so every place owner and every operator arrives from
 * the BFF's single address, and a per-address allowance would be one allowance for all
 * of them. The BFF proves itself with a shared secret (`X-CR7-Bff-Key`, compared in
 * constant time against `services.bff.key`) and names the browser's address in
 * `X-CR7-Client-Ip`; only then is that header believed. From anyone else it is ignored,
 * so it cannot be used to walk out of a bucket. No `TrustProxies` change: the dashboards'
 * egress address on shared hosting is neither known nor stable, and trusting
 * `X-Forwarded-For` from an address would hand the same escape to anybody behind it.
 *
 * **IPv6 is counted by its /64.** That is what one subscriber is given, and a caller who
 * holds a /64 can rotate through 2^64 addresses — per-address counting would be
 * meaningless.
 *
 * **The install.** `X-Device-Id`, which the app sends from its first launch. Optional —
 * the dashboards send none — and only a helper: whoever omits it or rotates it is still
 * held by the per-phone and per-address counts.
 */
final class OtpRequester
{
    public const BFF_KEY_HEADER = 'X-CR7-Bff-Key';

    public const CLIENT_IP_HEADER = 'X-CR7-Client-Ip';

    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?string $deviceId = null,
    ) {}

    public static function fromRequest(?Request $request): self
    {
        if ($request === null) {
            return new self;
        }

        return new self(self::bucket(self::ip($request)), self::deviceId($request));
    }

    /** The caller's address — the browser's, when the dashboards' BFF vouches for it. */
    public static function ip(Request $request): ?string
    {
        $key = (string) config('services.bff.key');
        $presented = $request->header(self::BFF_KEY_HEADER);
        $forwarded = $request->header(self::CLIENT_IP_HEADER);

        if (strlen($key) >= 32 && is_string($presented) && hash_equals($key, $presented) && is_string($forwarded)) {
            $forwarded = trim($forwarded);

            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        return $request->ip();
    }

    /** What an address is counted as: itself, or its /64 for IPv6. */
    public static function bucket(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        $prefix = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $prefix === false ? $ip : $prefix.'/64';
    }

    /** `X-Device-Id` when it looks like an install id (a UUID, or any 8–64 safe characters). */
    public static function deviceId(Request $request): ?string
    {
        $raw = $request->header('X-Device-Id');

        if (! is_string($raw)) {
            return null;
        }

        $raw = strtolower(trim($raw));

        return preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $raw) === 1 ? $raw : null;
    }
}
