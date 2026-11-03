<?php

namespace App\Support\Amendments;

use App\Models\DeviceToken;
use App\Models\User;

/**
 * doc 99 §4 — «Customer's app must understand it». An amendment is answered on a screen
 * 1.0.24 introduced; a 1.0.23 customer would receive a push that opens nothing, and the
 * order would sit until the change expired. So the place is told up front.
 *
 * The version is the one the customer's **most recently used** device registered
 * (`device_tokens.app_version`, «1.0.23+29»). No device, or one that never sent a
 * version, is treated as too old: nothing on the platform could reach the screen.
 */
final class CustomerAppVersion
{
    public const MINIMUM = '1.0.24';

    public static function newest(User $customer): ?string
    {
        $token = DeviceToken::query()
            ->where('user_id', $customer->id)
            ->where('app', 'customer')
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first(['app_version']);

        $version = $token?->app_version;

        if ($version === null || $version === '') {
            return null;
        }

        // «1.0.23+29» — the build number is not part of the comparison.
        return explode('+', $version, 2)[0];
    }

    public static function understandsAmendments(User $customer): bool
    {
        $version = self::newest($customer);

        return $version !== null && version_compare($version, self::MINIMUM, '>=');
    }
}
