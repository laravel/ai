<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Gateway\OpenAiVideoGateway;

trait HasVideoGateway
{
    protected ?VideoGateway $videoGateway = null;

    /**
     * Get the provider's video gateway.
     */
    public function videoGateway(): VideoGateway
    {
        return $this->videoGateway ??= new OpenAiVideoGateway;
    }

    /**
     * Set the provider's video gateway.
     */
    public function useVideoGateway(VideoGateway $gateway): self
    {
        $this->videoGateway = $gateway;

        return $this;
    }
}
