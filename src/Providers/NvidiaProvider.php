<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Nvidia\NvidiaGateway;

class NvidiaProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new NvidiaGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     *
     * Curated to NVIDIA build catalog free-tier models. Override via
     * config('ai.providers.nvidia.models.text.default').
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'meta/llama-3.3-70b-instruct';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'meta/llama-3.1-8b-instruct';
    }

    /**
     * Get the name of the smartest text model.
     *
     * Held on the free tier. Heavier reasoning models on NVIDIA's catalog
     * (e.g. nemotron-super-49b) are gated behind paid credits and so are
     * not the default here.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'meta/llama-3.3-70b-instruct';
    }
}
