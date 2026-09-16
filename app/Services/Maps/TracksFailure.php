<?php

namespace App\Services\Maps;

/**
 * {@see ReportsFailure}, for a provider that reaches the network.
 *
 * The flag is per call and is cleared at the start of each one, so it always
 * describes the request that just happened rather than the worst one this
 * process ever made. A call served out of the cache never sets it — nothing
 * failed, because nothing was sent.
 */
trait TracksFailure
{
    private bool $callFailed = false;

    public function lastCallFailed(): bool
    {
        return $this->callFailed;
    }
}
