<?php

namespace Laravel\Ai\Contracts\Files;

interface HasStream
{
    /**
     * Open a fresh read stream of the file; the caller closes it.
     *
     * @return resource
     */
    public function stream(): mixed;
}
