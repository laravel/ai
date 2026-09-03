<?php

namespace Tests\Fixtures;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

class CapturingStepGateway implements StepTextGateway
{
    /** @var array<int, array{model: string, instructions: ?string, tools: array, schema: ?array, context: StepContext}> */
    public array $calls = [];

    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        $this->calls[] = ['model' => $model, 'instructions' => $instructions, 'tools' => $tools, 'schema' => $schema, 'context' => $stepContext];

        return count($this->calls) === 1
            ? new StepResponse('', [new ToolCall('call_1', 'FixedNumberGenerator', [])], FinishReason::ToolCalls, new Usage(10, 5), new Meta, continuationToken: 'resp_1')
            : new StepResponse('Done.', [], FinishReason::Stop, new Usage, new Meta);
    }

    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        yield from [];
    }
}
