<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Groq\GroqGateway;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\WebSearch;

class GroqProvider extends Provider implements SupportsCodeExecution, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use Concerns\GeneratesText;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasTextGateway;
    use Concerns\HasTranscriptionGateway;
    use Concerns\StreamsText;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Groq);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return $search->providerOptions(Lab::Groq);
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new GroqGateway($this->events);
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new GroqGateway($this->events);
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

    /**
     * Get the name of the default transcription model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'whisper-large-v3-turbo';
    }
}
