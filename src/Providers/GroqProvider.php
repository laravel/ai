<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Groq\GroqGateway;

class GroqProvider extends Provider implements TextProvider
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
        return $this->textGateway ??= new GroqGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'openai/gpt-oss-120b';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'openai/gpt-oss-20b';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'openai/gpt-oss-120b';
    }
}
