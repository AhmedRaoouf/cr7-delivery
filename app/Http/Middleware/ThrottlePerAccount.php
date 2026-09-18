<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 68 — `throttle.account` on every signed-in group, `throttle.account:search` on
 * `GET /search` (ADMIN-LISTS.md §6).
 *
 * One bucket per account, its size by account type (`config/lists.php`), keyed by the
 * account and never the IP: both dashboards reach the API from one BFF address, and a
 * carrier NAT puts a town's phones behind a handful.
 *
 * **Not Laravel's `throttle:` middleware, on purpose.** That one counts in the default
 * cache store, which on this host is the database — so every signed-in request, polls
 * included, would have paid a locked read-modify-write on the `cache` table just to be
 * counted: the opposite of lighter under load. This counts in `lists.throttle.store`
 * (the file store in production). A file counter can undercount by a hit or two under a
 * burst of simultaneous requests; for an abuse ceiling sized at several requests a second
 * that is immaterial, and the security limiters (sign-in, OTP) keep their own exact ones.
 *
 * The refusal is the house `429` — `rate_limited`, `meta.retry_after` — rendered by
 * `bootstrap/app.php` from the `ThrottleRequestsException` thrown here.
 */
class ThrottlePerAccount
{
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next, string $mode = 'account'): Response
    {
        $limits = $mode === 'search' ? $this->searchLimits($request) : [$this->accountLimit($request)];
        $limiter = new RateLimiter(Cache::store(config('lists.throttle.store') ?: null));

        foreach ($limits as [$key, $max]) {
            if ($limiter->tooManyAttempts($key, $max)) {
                $retryAfter = max(1, $limiter->availableIn($key));

                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        $counts = [];
        foreach ($limits as [$key]) {
            $counts[] = $limiter->hit($key, self::DECAY_SECONDS);
        }

        $response = $next($request);

        [, $max] = $limits[0];
        $response->headers->set('X-RateLimit-Limit', (string) $max);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $max - $counts[0]));

        return $response;
    }

    /** @return array{0: string, 1: int} */
    private function accountLimit(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['account-ip:'.$request->ip(), $this->config('customer')];
        }

        $type = $user->type instanceof UserType ? $user->type->value : (string) $user->type;

        return ['account:'.$user->getKey(), $this->config($type)];
    }

    /**
     * Per account when a token is presented, and per IP always — generously, because a
     * town behind one carrier NAT searches from one address.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function searchLimits(Request $request): array
    {
        $user = auth('sanctum')->user();

        return array_values(array_filter([
            $user instanceof User ? ['search:'.$user->getKey(), $this->config('search_per_account')] : null,
            ['search-ip:'.$request->ip(), $this->config('search_per_ip')],
        ]));
    }

    private function config(string $key): int
    {
        return max(1, (int) config('lists.throttle.'.$key, 180));
    }
}
