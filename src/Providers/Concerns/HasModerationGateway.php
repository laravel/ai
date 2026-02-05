<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Gateway\Prism\PrismModerationGateway;

trait HasModerationGateway
{
    /**
     * The moderation gateway instance.
     */
    protected ?ModerationGateway $moderationGateway = null;

    /**
     * Get the provider's moderation gateway.
     */
    public function moderationGateway(): ModerationGateway
    {
        return $this->moderationGateway ??= new PrismModerationGateway($this->events);
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
