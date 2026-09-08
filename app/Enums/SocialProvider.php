<?php

namespace App\Enums;

/**
 * The two identity providers `CUS-07` offers. Nothing else is accepted — an unknown
 * value never reaches a lookup, it fails validation.
 */
enum SocialProvider: string
{
    case Apple = 'apple';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Apple => 'Apple',
            self::Google => 'Google',
        };
    }

    /**
     * The `iss` values the provider signs with. Google publishes both forms and has
     * used both, so both are accepted; Apple has only ever used one.
     *
     * @return array<int, string>
     */
    public function issuers(): array
    {
        return match ($this) {
            self::Apple => ['https://appleid.apple.com'],
            self::Google => ['https://accounts.google.com', 'accounts.google.com'],
        };
    }

    /** Where the signing keys live. Overridable per environment so a test can serve its own. */
    public function keysUrl(): string
    {
        return match ($this) {
            self::Apple => (string) config('services.apple.keys_url', 'https://appleid.apple.com/auth/keys'),
            self::Google => (string) config('services.google.keys_url', 'https://www.googleapis.com/oauth2/v3/certs'),
        };
    }

    /**
     * Every audience this deployment will accept — the iOS bundle id and the Android /
     * web OAuth client ids. An **empty** list is not «accept anything»: it is «this
     * provider is not configured», and `IdentityTokenVerifier` refuses outright.
     *
     * @return array<int, string>
     */
    public function clientIds(): array
    {
        $configured = config("services.{$this->value}.client_ids", []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        return array_values(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            is_array($configured) ? $configured : [],
        ), static fn (string $id) => $id !== ''));
    }
}
