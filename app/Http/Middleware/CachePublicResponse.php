<?php

namespace App\Http\Middleware;

use App\Support\Http\PublicCache;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 68 — `http.cache:{group},{ttl},{max-age}[,viewer]` on the public reads the customer
 * home opens on (ADMIN-LISTS.md §5).
 *
 * Three things, cheapest first:
 *
 *  1. **A stored answer.** The response body is kept in the cache store for `ttl` seconds
 *     under a key carrying its group's version ({@see PublicCache}); a hit is one read of
 *     one row, and the controller — with its five or six queries — does not run.
 *  2. **`304 Not Modified`.** Every answer carries a weak `ETag` over its body. A client
 *     that sends it back in `If-None-Match` gets a 304 and no body — on a hit, without the
 *     controller running at all.
 *  3. **`Cache-Control`.** `public, max-age` where the body is the same for everybody who
 *     reads that language (`Vary: Accept-Language`); `private, max-age=0,
 *     must-revalidate` where it follows the account (`viewer`: the first-order audience on
 *     banners, popups and `/config`), so no shared cache ever hands one account's answer
 *     to another.
 *
 * Only a `200` is stored or tagged; an error passes through untouched.
 */
class CachePublicResponse
{
    public function handle(Request $request, Closure $next, string $group = 'catalog', string $ttl = '60', string $maxAge = '30', string $vary = ''): Response
    {
        if (! $request->isMethodCacheable() || ! config('lists.http_cache.enabled', true)) {
            return $next($request);
        }

        PublicCache::flushMemo();

        $byViewer = $vary === 'viewer';
        $viewer = $byViewer ? PublicCache::viewer($request) : null;
        $key = PublicCache::keyFor($request, $group, $viewer);
        $entry = $key === null ? null : Cache::get($key);

        if (is_array($entry) && isset($entry['body'], $entry['etag'])) {
            $response = JsonResponse::fromJsonString($entry['body']);
            $etag = $entry['etag'];
            $response->headers->set('X-Cache', 'HIT');
        } else {
            $response = $next($request);

            if ($response->getStatusCode() !== 200 || ! $response instanceof JsonResponse) {
                return $response;
            }

            $body = (string) $response->getContent();
            $etag = PublicCache::etag($body);

            if ($key !== null) {
                Cache::put($key, ['body' => $body, 'etag' => $etag], max(1, (int) $ttl));
            }

            $response->headers->set('X-Cache', $key === null ? 'BYPASS' : 'MISS');
        }

        $response->setEtag($etag, true);

        $personal = $byViewer && $viewer !== 'guest';
        $response->headers->set('Cache-Control', match (true) {
            $personal => 'private, max-age=0, must-revalidate',
            (bool) config('lists.http_cache.public', true) => 'public, max-age='.max(0, (int) $maxAge),
            default => 'private, max-age='.max(0, (int) $maxAge),
        });

        $response->setVary(array_values(array_unique([
            ...$response->getVary(),
            'Accept-Language',
            ...($byViewer ? ['Authorization'] : []),
        ])));

        $response->isNotModified($request);

        return $response;
    }
}
