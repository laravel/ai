<?php

namespace Laravel\Ai\Gateway\Concerns;

use InvalidArgumentException;

trait EncodesResourceIds
{
    /**
     * Encode the given provider resource ID as a single URL path segment.
     *
     * @throws InvalidArgumentException
     */
    protected function resourceId(string $id): string
    {
        if (in_array($id, ['', '.', '..'], true)) {
            throw new InvalidArgumentException("[{$id}] is not a valid provider resource ID.");
        }

        return rawurlencode($id);
    }
}
