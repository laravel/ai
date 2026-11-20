<?php

namespace Laravel\Ai\Contracts\Files;

use Psr\Http\Message\StreamInterface;

interface HasStream
{
    /**
     * Open a fresh read stream of the file; the caller closes it.
     */
    public function stream(): StreamInterface;
}
