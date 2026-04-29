<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\TextGateway;

interface TextProvider
{
    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway;

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string;

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string;

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string;
}
