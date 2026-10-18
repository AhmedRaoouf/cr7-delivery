<?php

namespace App\Support\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use Throwable;

/**
 * On the shared host MySQL refuses a connection now and then when the machine is busy:
 * `SQLSTATE[HY000] [2002] Operation not permitted`, `[1040] Too many connections`,
 * `[2006] gone away` while connecting. Laravel retries once, and only for a lost
 * connection. The refusal happens before any statement is sent, so asking again is safe
 * for every kind of request, writes included; a short wait lets the host's limit clear.
 *
 * Three more tries, 150 ms / 400 ms / 900 ms, then the original exception goes out
 * unchanged so a real outage still looks like one.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    /** @var list<int> microseconds to wait before each extra attempt */
    public static array $waits = [150_000, 400_000, 900_000];

    /** Messages that mean "could not reach MySQL right now", not "the query is wrong". */
    private const TRANSIENT = [
        '[2002]',
        '[1040]',
        '[2006]',
        '[2013]',
        'Operation not permitted',
        'Too many connections',
        'Connection refused',
        'Resource temporarily unavailable',
    ];

    protected function tryAgainIfCausedByLostConnection(Throwable $e, $dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        if (! $this->isTransient($e) && ! $this->causedByLostConnection($e)) {
            throw $e;
        }

        $last = $e;

        foreach (self::$waits as $wait) {
            usleep($wait);

            try {
                return $this->createPdoConnection($dsn, $username, $password, $options);
            } catch (Throwable $retry) {
                $last = $retry;

                if (! $this->isTransient($retry) && ! $this->causedByLostConnection($retry)) {
                    throw $retry;
                }
            }
        }

        throw $last;
    }

    private function isTransient(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (self::TRANSIENT as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
