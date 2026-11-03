<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which build of the phone app is asking.
 *
 * Only the phone app sends `X-App-Version` (every build since 1.0.0, `1.0.23` without the
 * build number), and only beside `X-App: app`. A request without one — a dashboard, a
 * test, a script — is never treated as an old app: it is the current client.
 *
 * The two older checks (`LinePrice::isLegacyClient`, `PlaceOrder::isLegacyClient`) answer
 * the same question for their own thresholds and are left as they are; new thresholds
 * are asked here.
 */
final class ClientVersion
{
    /** The build that understands doc 99's `rating_badge` and stops reading `rating_avg` as the badge. */
    public const RATING_BADGE = '1.0.24';

    public static function isBefore(?Request $request, string $version): bool
    {
        if ($request === null || $request->header('X-App') !== 'app' || ! $request->hasHeader('X-App-Version')) {
            return false;
        }

        $sent = trim((string) $request->header('X-App-Version'));

        // `1.0.24+30` → `1.0.24`; anything that is not a version reads as current.
        $sent = explode('+', $sent)[0];

        if (preg_match('/^\d+(\.\d+){0,3}$/', $sent) !== 1) {
            return false;
        }

        return version_compare($sent, $version, '<');
    }
}
