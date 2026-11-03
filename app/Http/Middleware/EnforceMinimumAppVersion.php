<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ReviewAccess;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force update — the server half of the app's CUS-50 blocker.
 *
 * The phone app sends `X-App: app`, `X-App-Version` (`1.0.23` or `1.0.23+29`) and
 * `X-Platform` (`android` | `ios`) on every call. When the version is below the
 * platform's `app.force_update.{android,ios}_min_version`, the answer is `426` with
 * `error: force_update` and `meta.store_url` — exactly what the app's ErrorInterceptor
 * turns into `ForceUpdateException` and the blocker screen.
 *
 * Never blocks: a request without `X-App: app`, without a readable version or without a
 * known platform (dashboards, curl, tests, unknown clients); `GET /config` (the app reads
 * the minimum from it), `auth/refresh`, `auth/logout`; and a store-review account, so a
 * reviewer is never locked out. The default minimum is `1.0.0`, which blocks nobody.
 */
class EnforceMinimumAppVersion
{
    private const EXEMPT = ['api/v1/config', 'api/v1/auth/refresh', 'api/v1/auth/logout'];

    public const STORE_URL_ANDROID = 'https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery';

    public const STORE_URL_IOS = 'https://apps.apple.com/app/id6815852436';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mustUpdate($request)) {
            $platform = strtolower(trim((string) $request->header('X-Platform')));

            return ApiResponse::error(
                'force_update',
                'يجب تحديث التطبيق إلى أحدث إصدار للمتابعة',
                426,
                meta: ['store_url' => self::storeUrl($platform), 'min_version' => self::minimum($platform)],
            );
        }

        return $next($request);
    }

    public static function minimum(string $platform): string
    {
        $key = $platform === 'ios' ? 'app.force_update.ios_min_version' : 'app.force_update.android_min_version';
        $value = trim((string) Settings::get($key, '1.0.0'));

        return self::parse($value) !== null ? $value : '1.0.0';
    }

    public static function storeUrl(string $platform): string
    {
        $key = $platform === 'ios' ? 'app.force_update.store_url_ios' : 'app.force_update.store_url_android';
        $value = trim((string) Settings::get($key, ''));

        if ($value !== '') {
            return $value;
        }

        return $platform === 'ios' ? self::STORE_URL_IOS : self::STORE_URL_ANDROID;
    }

    private function mustUpdate(Request $request): bool
    {
        if ($request->header('X-App') !== 'app' || ! $request->hasHeader('X-App-Version')) {
            return false;
        }

        $platform = strtolower(trim((string) $request->header('X-Platform')));

        if (! in_array($platform, ['android', 'ios'], true)) {
            return false;
        }

        $sent = self::parse((string) $request->header('X-App-Version'));

        if ($sent === null) {
            return false;
        }

        $minimum = self::parse(self::minimum($platform));

        if ($minimum === null || version_compare($sent, $minimum, '>=')) {
            return false;
        }

        return ! $this->exempt($request);
    }

    private function exempt(Request $request): bool
    {
        foreach (self::EXEMPT as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        $reviewers = ReviewAccess::phones();

        if ($reviewers === []) {
            return false;
        }

        $phone = $request->input('phone');

        if (is_string($phone) && ReviewAccess::isReviewPhone($phone)) {
            return true;
        }

        $user = $request->bearerToken() !== null ? $request->user('sanctum') : null;

        return $user instanceof User && ReviewAccess::isReviewPhone((string) $user->phone);
    }

    /** `1.0.23+29` → `1.0.23`; null when it is not a version (so it reads as current). */
    private static function parse(string $raw): ?string
    {
        $version = explode('+', trim($raw))[0];

        return preg_match('/^\d+(\.\d+){0,3}$/', $version) === 1 ? $version : null;
    }
}
