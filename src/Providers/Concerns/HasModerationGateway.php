<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\ModerationGateway;

trait HasModerationGateway
{
    protected ModerationGateway $moderationGateway;

    /**
     * Get the provider's moderation gateway.
     */
    public function moderationGateway(): ModerationGateway
    {
        return $this->moderationGateway;
    }

    /**
     * Set the provider's moderation gateway.
     */
    public function useModerationGateway(ModerationGateway $gateway): self
    {
        $this->moderationGateway = $gateway;

        return $this;
    }
}
