<?php

namespace App\Support\Security;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Batch 67 — `GET /geo/expand-link` without the open door.
 *
 * The route is the one place the server fetches a URL somebody else chose, and it
 * checked the host of the **first** URL only, then let Guzzle follow up to five
 * redirects to wherever they pointed. Every host on the allow-list — google.com,
 * bing.com, apple.com — runs open redirectors (`google.com/url?q=…`,
 * `bing.com/ck/a?…`), so `https://www.google.com/url?q=http://127.0.0.1:3306/`
 * passed the check and the server then connected to whatever the redirect named:
 * localhost, the host's private network, a cloud metadata address. The answer
 * (`map_link_unreachable` versus `map_link_has_no_point`, and the time taken) was
 * enough to map which internal ports are open.
 *
 * Now every hop is resolved and checked against the same allow-list before it is
 * requested, redirects are followed here rather than by Guzzle, and a hop that leaves
 * the allow-list ends the walk at the last URL that was allowed — which, for a real
 * map link, already carries the point.
 */
final class MapLinkFetcher
{
    public const MAX_HOPS = 5;

    /** Only the first part of a page is searched for a pin; a map page is not a download. */
    private const MAX_BODY_BYTES = 1_048_576;

    /**
     * The domains a pasted map link may start at **and** pass through. A host matches
     * when it is one of these or a subdomain of one.
     */
    private const HOSTS = [
        'goo.gl', 'g.co', 'google.com', 'apple.com', 'openstreetmap.org', 'osm.org',
        'waze.com', 'bing.com',
    ];

    /**
     * Google answers from its country domains too — a pin opened from Egypt lands on
     * `www.google.com.eg/maps/…`. `google.<cc>` and `google.co.<cc>` / `google.com.<cc>`.
     */
    private const GOOGLE_COUNTRY = '/^(?:[a-z0-9-]+\.)*google\.(?:(?:com?)\.)?[a-z]{2,3}$/';

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        // Userinfo in a map link is never legitimate, and it is the usual way to make a
        // URL read as one host to a person and another to a parser.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // A port on a map link is never legitimate either: Google, Apple and OSM all
        // answer on the default ones.
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        return self::isMapHost(strtolower((string) $parts['host']));
    }

    public static function isMapHost(string $host): bool
    {
        $host = rtrim($host, '.');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (self::HOSTS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return preg_match(self::GOOGLE_COUNTRY, $host) === 1;
    }

    /**
     * Follow the link hop by hop, never leaving the allow-list.
     *
     * @return array{final_url: string, visited: list<string>, body: string}
     *
     * @throws \Throwable when the first request cannot be made at all
     */
    public static function follow(string $url): array
    {
        $visited = [$url];
        $current = $url;
        $body = '';

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            /** @var Response $response */
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; CR7Delivery/1.0)',
                'Accept-Language' => 'ar,en;q=0.8',
            ])
                ->withoutRedirecting()
                ->connectTimeout(4)
                ->timeout(8)
                ->get($current);

            $location = $response->header('Location');

            if ($response->redirect() && $location !== '') {
                $next = (string) UriResolver::resolve(new Uri($current), new Uri($location));

                if (! self::isAllowed($next) || $hop === self::MAX_HOPS) {
                    // The redirect is still worth reading for a pin — a Google short
                    // link's Location usually *is* the answer — but it is never fetched.
                    $visited[] = $next;

                    return ['final_url' => $current, 'visited' => $visited, 'body' => ''];
                }

                $visited[] = $next;
                $current = $next;

                continue;
            }

            $body = substr((string) $response->body(), 0, self::MAX_BODY_BYTES);

            break;
        }

        return ['final_url' => $current, 'visited' => $visited, 'body' => $body];
    }
}
