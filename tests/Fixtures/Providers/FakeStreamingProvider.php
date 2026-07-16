<?php

namespace Tests\Fixtures\Providers;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class FakeStreamingProvider extends Provider implements TextProvider
{
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
        protected Closure $streamFactory,
    ) {}

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        return ($this->streamFactory)($this, $prompt);
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new BadMethodCallException('FakeStreamingProvider::prompt is not implemented.');
    }

    public function useTextGateway(StepTextGateway $gateway): self
    {
        return $this;
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        throw new BadMethodCallException('FakeStreamingProvider::textGenerationLoop is not implemented.');
    }

    public function defaultTextModel(): string
    {
        return 'fake-model';
    }

    public function cheapestTextModel(): string
    {
        return 'fake-model';
    }

    public function smartestTextModel(): string
    {
        return 'fake-model';
    }
}
