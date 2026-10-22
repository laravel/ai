<?php

namespace Laravel\Ai\Contracts\Files;

interface HasStream
{
    /**
     * Open a fresh readable stream for an upload attempt.
     *
     * The caller is responsible for closing the returned resource.
     *
     * @return resource
     */
    public function stream(): mixed;
}
