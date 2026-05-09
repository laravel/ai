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

interface TextGateway
{
    /**
     * Generate text for a single LLM turn.
     *
     * Gateways make exactly one LLM call and return the raw result.
     * The multi-step tool loop is handled by the orchestrator.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @param  string|null  $previousResponseId  Opaque provider response ID from a prior turn for stateful continuation.
     * @param  StepContext|null  $context  Per-call orchestration hints (e.g. is-final-step).
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): SingleTurnResponse;

    /**
     * Stream text for a single LLM turn.
     *
     * Gateways stream events for exactly one LLM call.
     * The multi-step tool loop is handled by the orchestrator.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @param  string|null  $previousResponseId  Opaque provider response ID from a prior turn for stateful continuation.
     * @param  StepContext|null  $context  Per-call orchestration hints (e.g. is-final-step).
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): Generator;
}
