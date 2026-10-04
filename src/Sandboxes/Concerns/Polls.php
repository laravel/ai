<?php

namespace Laravel\Ai\Sandboxes\Concerns;

use Closure;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;

trait Polls
{
    /**
     * Call the check every two seconds until it returns something other than null, failing at the deadline.
     *
     * @template TResult
     *
     * @param  Closure(): (TResult|null)  $check
     * @return TResult
     *
     * @throws SandboxException
     */
    protected function poll(int $seconds, Closure $check, string $timeoutMessage, ?string $id = null): mixed
    {
        $deadline = now()->addSeconds($seconds);

        while (true) {
            if (($result = $check()) !== null) {
                return $result;
            }

            if (now()->isAfter($deadline)) {
                throw new SandboxException($timeoutMessage, $this->name(), $id);
            }

            Sleep::for(2)->seconds();
        }
    }
}
