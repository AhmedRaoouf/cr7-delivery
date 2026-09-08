<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * A stand-in for Apple and Google: a real RSA key, a real JWKS served over a faked HTTP
 * client, and real RS256 signatures. Nothing here weakens the verifier — every token these
 * helpers build is checked by exactly the code that checks a live one.
 */
trait SignsIdentityTokens
{
    protected const IDENTITY_KID = 'cr7-test-key';

    private static ?OpenSSLAsymmetricKey $identityKey = null;

    /** Points both providers at a JWKS this test serves, and clears any key cached by a previous one. */
    protected function fakeIdentityProviders(
        string $googleClientIds = 'cr7-google-client',
        string $appleClientIds = 'com.cr7delivery.cr7Delivery',
    ): void {
        Cache::flush();

        config([
            'services.google.client_ids' => $googleClientIds,
            'services.google.keys_url' => 'https://jwks.test/google',
            'services.apple.client_ids' => $appleClientIds,
            'services.apple.keys_url' => 'https://jwks.test/apple',
        ]);

        Http::fake([
            'https://jwks.test/*' => Http::response(['keys' => [$this->identityJwk()]]),
        ]);
    }

    /** @param  array<string, mixed>  $claims */
    protected function signIdentityToken(array $claims, ?OpenSSLAsymmetricKey $key = null): string
    {
        $header = $this->base64UrlEncode((string) json_encode([
            'alg' => 'RS256', 'kid' => self::IDENTITY_KID, 'typ' => 'JWT',
        ]));
        $payload = $this->base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_UNICODE));

        openssl_sign($header.'.'.$payload, $signature, $key ?? $this->identityKey(), OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->base64UrlEncode($signature);
    }

    /** A Google id_token for `$subject`, valid for ten minutes. */
    protected function googleToken(string $subject, array $extra = []): string
    {
        return $this->signIdentityToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'cr7-google-client',
            'sub' => $subject,
            'exp' => time() + 600,
            'iat' => time(),
        ] + $extra);
    }

    /** An Apple id_token. Apple never puts a name on one — that is the point of the flow. */
    protected function appleToken(string $subject, array $extra = []): string
    {
        return $this->signIdentityToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.cr7delivery.cr7Delivery',
            'sub' => $subject,
            'exp' => time() + 600,
            'iat' => time(),
        ] + $extra);
    }

    /** @return array<string, string> */
    protected function identityJwk(): array
    {
        $details = openssl_pkey_get_details($this->identityKey());

        return [
            'kid' => self::IDENTITY_KID,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    /** Generated once for the whole run — a 2048-bit key per test is the slowest thing here. */
    protected function identityKey(): OpenSSLAsymmetricKey
    {
        return self::$identityKey ??= openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
