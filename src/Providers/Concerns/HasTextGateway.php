<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\TextGenerationLoop;

trait HasTextGateway
{
    protected StepTextGateway $textGateway;

    protected ?TextGenerationLoop $textGenerationLoop = null;

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ?? $this->gateway;
    }

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(StepTextGateway $gateway): self
    {
        $this->textGateway = $gateway;
        $this->textGenerationLoop = null;

        return $this;
    }

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     */
    public function textGenerationLoop(): TextGenerationLoop
    {
        return $this->textGenerationLoop ??= new TextGenerationLoop($this->textGateway(), $this->events);
    }
}
