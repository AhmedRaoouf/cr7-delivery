<?php

namespace Tests\Unit;

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Support\Social\IdentityTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SignsIdentityTokens;
use Tests\TestCase;

/**
 * The identity is whatever survives this class, and nothing the client claims.
 *
 * There is no JWT package in `composer.json`, so the RS256 check is hand-rolled over
 * `openssl_verify` — which means the DER assembly of the public key is application code
 * and has to be tested like application code. These tests sign with a key they generate
 * themselves and serve their own JWKS, so a real signature is proved to pass and each
 * forgery is proved to fail.
 */
class SocialIdentityTokenTest extends TestCase
{
    use SignsIdentityTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeIdentityProviders();
    }

    public function test_a_real_signature_from_the_published_key_is_accepted(): void
    {
        $token = $this->googleToken('google-subject-1', [
            'email' => 'ahmed@example.com',
            'email_verified' => true,
            'given_name' => 'أحمد',
            'family_name' => 'الشناوي',
        ]);

        $identity = app(IdentityTokenVerifier::class)->verify(SocialProvider::Google, $token);

        $this->assertSame('google-subject-1', $identity->subject);
        $this->assertSame('ahmed@example.com', $identity->email);
        $this->assertSame('أحمد الشناوي', $identity->name);
        $this->assertTrue($identity->emailVerified);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        // Everything about this token is right except the last byte of the signature —
        // which is the whole point of signing it.
        [$header, $payload, $signature] = explode('.', $this->googleToken('google-subject-1'));

        $bytes = $this->base64UrlDecode($signature);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);

        $this->assertRejects(SocialProvider::Google, $header.'.'.$payload.'.'.$this->base64UrlEncode($bytes));
    }

    public function test_a_token_signed_by_a_different_key_is_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $token = $this->signIdentityToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'cr7-google-client',
            'sub' => 'google-subject-1',
            'exp' => time() + 600,
        ], key: $other);

        $this->assertRejects(SocialProvider::Google, $token);
    }

    public function test_an_unsigned_token_is_rejected(): void
    {
        // `alg: none` — the oldest forgery there is, and the one a naive decoder accepts.
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'none', 'kid' => self::IDENTITY_KID]));
        $payload = $this->base64UrlEncode((string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'cr7-google-client',
            'sub' => 'google-subject-1',
            'exp' => time() + 600,
        ]));

        $this->assertRejects(SocialProvider::Google, $header.'.'.$payload.'.');
    }

    public function test_a_token_minted_for_another_application_is_rejected(): void
    {
        $token = $this->signIdentityToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'somebody-elses-client',
            'sub' => 'google-subject-1',
            'exp' => time() + 600,
        ]);

        $this->assertRejects(SocialProvider::Google, $token);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->signIdentityToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'cr7-google-client',
            'sub' => 'google-subject-1',
            'exp' => time() - 3600,
        ]);

        $this->assertRejects(SocialProvider::Google, $token);
    }

    public function test_a_google_token_cannot_be_replayed_as_an_apple_one(): void
    {
        $token = $this->signIdentityToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'com.cr7delivery.cr7Delivery',
            'sub' => 'google-subject-1',
            'exp' => time() + 600,
        ]);

        $this->assertRejects(SocialProvider::Apple, $token);
    }

    /** Apple sends `email_verified` as the string `"true"`; Google sends a boolean. */
    public function test_apples_string_flag_reads_as_a_boolean(): void
    {
        $token = $this->appleToken('apple-subject-1', [
            'email' => 'relay@privaterelay.appleid.com',
            'email_verified' => 'true',
        ]);

        $identity = app(IdentityTokenVerifier::class)->verify(SocialProvider::Apple, $token);

        $this->assertTrue($identity->emailVerified);
        // Apple never puts a name on the token — not even on the first authorisation.
        $this->assertNull($identity->name);
    }

    /**
     * An empty allow-list is «not configured», never «accept anything». Without this the
     * endpoint would take a token minted for any other app on earth.
     */
    public function test_a_provider_with_no_client_ids_refuses_outright(): void
    {
        config(['services.google.client_ids' => '']);

        try {
            app(IdentityTokenVerifier::class)->verify(SocialProvider::Google, $this->googleToken('google-subject-1'));
            $this->fail('an unconfigured provider must not verify anything');
        } catch (ApiException $exception) {
            $this->assertSame('social_not_configured', $exception->errorCode);
            $this->assertSame(501, $exception->status);
        }
    }

    /** Both providers rotate keys without warning, so a `kid` miss forces one refetch. */
    public function test_an_unknown_kid_refetches_the_key_set_once(): void
    {
        Cache::put('social:jwks:google', [['kid' => 'stale-key', 'kty' => 'RSA', 'n' => 'AA', 'e' => 'AQAB']], 600);

        $identity = app(IdentityTokenVerifier::class)->verify(SocialProvider::Google, $this->googleToken('google-subject-1'));

        $this->assertSame('google-subject-1', $identity->subject);
    }

    private function assertRejects(SocialProvider $provider, string $token): void
    {
        try {
            app(IdentityTokenVerifier::class)->verify($provider, $token);
            $this->fail('the token should not have verified');
        } catch (ApiException $exception) {
            $this->assertSame('social_token_invalid', $exception->errorCode);
            $this->assertSame(401, $exception->status);
        }
    }
}
