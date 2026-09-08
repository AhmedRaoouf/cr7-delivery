<?php

namespace App\Support\Social;

use App\Enums\SocialProvider;

/**
 * Everything a verified `id_token` actually contains, and nothing more.
 *
 * There is no phone number on it, from either provider — see the migration
 * `2026_09_14_090000_social_sign_in`. `subject` is the provider's own stable id (`sub`)
 * and is the only field that identifies the person; the email and the name are display
 * data and may both be absent.
 */
final class SocialIdentity
{
    public function __construct(
        public readonly SocialProvider $provider,
        public readonly string $subject,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly bool $emailVerified = false,
    ) {}
}
