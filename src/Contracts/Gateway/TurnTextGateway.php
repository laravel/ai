<?php

namespace Laravel\Ai\Contracts\Gateway;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Gateway\TurnResponse;
use Laravel\Ai\Gateway\TurnStreamEnd;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\StreamEvent;

/** @internal */
interface TurnTextGateway
{
    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function handleTurn(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): TurnResponse;

    /**
     * Must yield exactly one {@see TurnStreamEnd} as the final item on success, or an Error event and no TurnStreamEnd on failure; if it yields neither, the loop emits a terminal StreamEnd with FinishReason::Error so consumers never hang.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, StreamEvent|TurnStreamEnd>
     */
    public function streamTurn(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator;
}
