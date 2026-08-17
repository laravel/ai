<?php

namespace Tests\Fixtures;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class RecordingStepGateway implements StepTextGateway
{
    public string $model = '';

    public ?string $instructions = null;

    /** @var Message[] */
    public array $received = [];

    public array $tools = [];

    public ?array $schema = null;

    public ?TextGenerationOptions $options = null;

    public ?int $timeout = null;

    public ?TextProvider $provider = null;

    public int $steps = 0;

    /**
     * @param  StepResponse[]  $responses  Responses to return per step; falls back to a plain "ok" stop response.
     */
    public function __construct(protected array $responses = []) {}

    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        return $this->record($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
    }

    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        return $this->record($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);

        yield;
    }

    protected function record(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): StepResponse
    {
        $this->model = $model;
        $this->instructions = $instructions;
        $this->received = $messages;
        $this->tools = $tools;
        $this->schema = $schema;
        $this->options = $options;
        $this->timeout = $timeout;
        $this->provider = $provider;
        $this->steps++;

        return array_shift($this->responses)
            ?? new StepResponse('ok', [], FinishReason::Stop, new Usage, new Meta($provider->name(), $model));
    }
}
