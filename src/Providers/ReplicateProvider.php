<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\ReplicateGateway;

class ReplicateProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\StreamsText;

    protected ?TextGateway $textGateway = null;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new ReplicateGateway;
    }

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self
    {
        $this->textGateway = $gateway;

        return $this;
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'meta/meta-llama-3-70b-instruct';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'meta/meta-llama-3-8b-instruct';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'meta/meta-llama-3.1-405b-instruct';
    }
}
