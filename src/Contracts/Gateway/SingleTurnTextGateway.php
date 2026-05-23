<?php

namespace Laravel\Ai\Contracts\Gateway;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;

/**
 * A gateway that exposes a single provider turn at a time, leaving the
 * multi-step tool loop to the orchestrator (StepLoop).
 *
 * Gateways that implement this also implement {@see TextGateway}; the
 * TextGateway methods are expected to delegate to StepLoop, which calls
 * back into the single-turn methods defined here.
 */
interface SingleTurnTextGateway
{
    /**
     * Execute a single provider turn and return its raw outcome.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateSingleTurn(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): SingleTurnResponse;

    /**
     * Stream a single provider turn, yielding events and concluding with a StreamEnd
     * that carries the turn's finish reason, response id, and any provider content blocks.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function streamSingleTurn(
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
