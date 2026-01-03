<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\FileGateway;

trait HasFileGateway
{
    protected FileGateway $fileGateway;

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway;
    }

    /**
     * Set the provider's file gateway.
     */
    public function useFileGateway(FileGateway $gateway): self
    {
        $this->fileGateway = $gateway;

        return $this;
    }
}
