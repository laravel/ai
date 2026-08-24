<?php

namespace Laravel\Ai\Gateway\Concerns;

use RuntimeException;

trait ScriptsFakeResponses
{
    protected int $currentResponseIndex = 0;

    /**
     * Get the next scripted response, or null when the fake was given no script.
     *
     * @throws RuntimeException if a non-empty script has already been consumed.
     */
    protected function nextScriptedResponse(mixed ...$arguments): mixed
    {
        $index = $this->currentResponseIndex++;

        if (! is_array($this->responses)) {
            return call_user_func($this->responses, ...$arguments);
        }

        $responses = array_values($this->responses);

        if ($responses === []) {
            return null;
        }

        if ($index >= count($responses)) {
            throw new RuntimeException(sprintf(
                'Fake %s responses exhausted: [%d] response(s) were supplied but call [%d] was made. '
                .'Add the missing responses, or pass a Closure to answer every call.',
                $this->scriptNoun,
                count($responses),
                $index + 1,
            ));
        }

        return $responses[$index];
    }
}
