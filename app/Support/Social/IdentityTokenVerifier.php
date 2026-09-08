<?php

namespace App\Support\Social;

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies an Apple / Google `id_token` **server-side**. Nothing a client says about who
 * it is survives this class: the token is only an identity once its signature has been
 * checked against the provider's own published key.
 *
 * There is no JWT package in `composer.json` and adding one was not on the table, so this
 * is RS256 by hand — `openssl_verify` over the signing input, against a PEM assembled from
 * the JWKS `n`/`e` pair. The DER assembly is the fiddly half and is covered by
 * `tests/Unit/SocialIdentityTokenTest`, which signs with a real key it generates itself.
 *
 * Three refusals, all of them 401 `social_token_invalid` so a caller cannot tell a forged
 * signature from a wrong audience from an expired token:
 *
 * * the signature does not verify against any key the provider publishes;
 * * `iss` is not the provider's, or `aud` is not one of ours;
 * * `exp` is in the past (60 s of leeway for clock skew, and no more).
 *
 * An **unconfigured** provider is a different thing and says so: with no client ids there
 * is no audience to check against, and a verifier that accepted every audience would let
 * a token minted for any other app in the world sign somebody in here.
 */
class IdentityTokenVerifier
{
    /** Google rotates roughly daily and publishes `Cache-Control`; twelve hours is well inside it. */
    public const JWKS_TTL_SECONDS = 43200;

    private const CLOCK_SKEW_SECONDS = 60;

    private const HTTP_TIMEOUT_SECONDS = 6;

    public function verify(SocialProvider $provider, string $idToken): SocialIdentity
    {
        $audiences = $provider->clientIds();

        if ($audiences === []) {
            // 501, not 503. The app maps every 503 onto `MaintenanceException` and shows
            // the whole-platform maintenance screen — «الخدمة تحت الصيانة» for a button
            // nobody has configured yet would be a support call an hour long.
            throw new ApiException(
                'social_not_configured',
                'تسجيل الدخول عبر هذه الخدمة غير مفعّل حاليًا',
                501,
                meta: ['provider' => $provider->value],
            );
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $this->segments($idToken);

        $header = $this->decodeJson($headerSegment);
        $payload = $this->decodeJson($payloadSegment);

        // `alg: none` and the HMAC family are the two classic forgeries: one drops the
        // signature entirely, the other invites us to verify with the public key as a
        // shared secret. Only RS256 is ever accepted here.
        if (($header['alg'] ?? null) !== 'RS256') {
            $this->reject();
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            $this->reject();
        }

        $signature = $this->base64UrlDecode($signatureSegment);
        $signingInput = $headerSegment.'.'.$payloadSegment;

        if (! $this->signatureIsValid($provider, $kid, $signingInput, $signature)) {
            $this->reject();
        }

        $this->assertClaims($provider, $payload, $audiences);

        return new SocialIdentity(
            provider: $provider,
            subject: (string) $payload['sub'],
            email: $this->stringOrNull($payload['email'] ?? null),
            name: $this->name($payload),
            emailVerified: $this->boolClaim($payload['email_verified'] ?? null),
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function segments(string $idToken): array
    {
        $segments = explode('.', trim($idToken));

        if (count($segments) !== 3 || in_array('', $segments, true)) {
            $this->reject();
        }

        return [$segments[0], $segments[1], $segments[2]];
    }

    /**
     * The key is looked up by `kid`, and a miss forces **one** refetch before giving up.
     * Both providers rotate their keys without warning, and a cached JWKS that predates a
     * rotation would otherwise refuse every real sign-in for up to twelve hours.
     */
    private function signatureIsValid(SocialProvider $provider, string $kid, string $signingInput, string $signature): bool
    {
        $key = $this->findKey($this->jwks($provider), $kid);

        if ($key === null) {
            $key = $this->findKey($this->jwks($provider, refresh: true), $kid);
        }

        if ($key === null) {
            return false;
        }

        $pem = $this->pemFrom($key);

        if ($pem === null) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $audiences
     */
    private function assertClaims(SocialProvider $provider, array $payload, array $audiences): void
    {
        if (! in_array($payload['iss'] ?? null, $provider->issuers(), true)) {
            $this->reject();
        }

        $aud = $payload['aud'] ?? null;
        $claimed = is_array($aud) ? array_values(array_filter($aud, 'is_string')) : (is_string($aud) ? [$aud] : []);

        if (array_intersect($claimed, $audiences) === []) {
            $this->reject();
        }

        $exp = $payload['exp'] ?? null;

        if (! is_numeric($exp) || (int) $exp + self::CLOCK_SKEW_SECONDS <= time()) {
            $this->reject();
        }

        $nbf = $payload['nbf'] ?? $payload['iat'] ?? null;

        if (is_numeric($nbf) && (int) $nbf - self::CLOCK_SKEW_SECONDS > time()) {
            $this->reject();
        }

        if (! isset($payload['sub']) || ! is_string($payload['sub']) || $payload['sub'] === '') {
            $this->reject();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jwks(SocialProvider $provider, bool $refresh = false): array
    {
        $cacheKey = "social:jwks:{$provider->value}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $keys = Cache::remember($cacheKey, self::JWKS_TTL_SECONDS, function () use ($provider): array {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->get($provider->keysUrl());
            } catch (Throwable) {
                $response = null;
            }

            if ($response === null || ! $response->successful()) {
                // 502 for the same reason 501 is used above: a 503 reads as
                // «the whole platform is down» on every client.
                throw new ApiException(
                    'social_provider_unavailable',
                    'تعذّر الوصول إلى خدمة تسجيل الدخول، حاول مرة أخرى',
                    502,
                    meta: ['provider' => $provider->value],
                );
            }

            $keys = $response->json('keys');

            return is_array($keys) ? $keys : [];
        });

        return is_array($keys) ? $keys : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private function findKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? 'RSA') === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /**
     * A JWKS entry carries the modulus and the exponent, not a key file, so the
     * SubjectPublicKeyInfo has to be assembled before OpenSSL will look at it.
     *
     * @param  array<string, mixed>  $key
     */
    private function pemFrom(array $key): ?string
    {
        $n = $key['n'] ?? null;
        $e = $key['e'] ?? null;

        if (! is_string($n) || ! is_string($e) || $n === '' || $e === '') {
            return null;
        }

        $modulus = $this->positiveInteger($this->base64UrlDecode($n));
        $exponent = $this->positiveInteger($this->base64UrlDecode($e));

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $sequence = $this->der(0x02, $modulus).$this->der(0x02, $exponent);
        $rsaKey = $this->der(0x30, $sequence);

        // AlgorithmIdentifier: SEQUENCE { OID 1.2.840.113549.1.1.1 (rsaEncryption), NULL }
        $algorithm = pack('H*', '300d06092a864886f70d0101010500');
        $bitString = $this->der(0x03, chr(0).$rsaKey);

        $der = $this->der(0x30, $algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * ASN.1 INTEGER is signed. A 2048-bit modulus always has its top bit set, so without
     * the leading zero every key reads as a negative number and OpenSSL rejects the PEM.
     */
    private function positiveInteger(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        return ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes;
    }

    private function der(int $tag, string $value): string
    {
        return chr($tag).$this->derLength(strlen($value)).$value;
    }

    private function derLength(int $length): string
    {
        if ($length <= 0x7F) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $decoded = json_decode($this->base64UrlDecode($segment), true);

        if (! is_array($decoded)) {
            $this->reject();
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /** Google puts a `name` on the token; Apple never does — see `SocialSignInAction`. */
    private function name(array $payload): ?string
    {
        $name = $this->stringOrNull($payload['name'] ?? null);

        if ($name !== null) {
            return $name;
        }

        $parts = array_filter([
            $this->stringOrNull($payload['given_name'] ?? null),
            $this->stringOrNull($payload['family_name'] ?? null),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Apple sends `email_verified` as the string `"true"`; Google sends a boolean. */
    private function boolClaim(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    private function reject(): never
    {
        throw new ApiException('social_token_invalid', 'تعذّر التحقق من هويتك لدى مزوّد الخدمة', 401);
    }
}
