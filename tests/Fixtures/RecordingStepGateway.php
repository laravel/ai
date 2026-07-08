<?php

namespace Tests\Fixtures;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\DelegatesToTextGenerationLoop;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class RecordingStepGateway implements StepTextGateway, TextGateway
{
    use DelegatesToTextGenerationLoop;

    public string $model = '';

    public ?string $instructions = null;

    /** @var Message[] */
    public array $received = [];

    public array $tools = [];

    public int $steps = 0;

    /**
     * @param  StepResponse[]  $responses  Responses to return per step; falls back to a plain "ok" stop response.
     */
    public function __construct(protected array $responses = []) {}

    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        return $this->record($provider, $model, $instructions, $messages, $tools);
    }

    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        return $this->record($provider, $model, $instructions, $messages, $tools);

        yield;
    }

    protected function record(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools): StepResponse
    {
        $this->model = $model;
        $this->instructions = $instructions;
        $this->received = $messages;
        $this->tools = $tools;
        $this->steps++;

        return array_shift($this->responses)
            ?? new StepResponse('ok', [], FinishReason::Stop, new Usage, new Meta($provider->name(), $model));
    }
}
