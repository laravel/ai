<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\TranslationGateway;

trait HasTranslationGateway
{
    protected TranslationGateway $translationGateway;

    /**
     * Get the provider's translation gateway.
     */
    public function translationGateway(): TranslationGateway
    {
        return $this->translationGateway ?? $this->gateway;
    }

    /**
     * Set the provider's translation gateway.
     */
    public function useTranslationGateway(TranslationGateway $gateway): self
    {
        $this->translationGateway = $gateway;

        return $this;
    }
}
