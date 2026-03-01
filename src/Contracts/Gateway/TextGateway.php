<?php

namespace Laravel\Ai\Contracts\Gateway;

use Closure;
use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

interface TextGateway
{
    /**
     * Generate text representing the next message in a conversation.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     * @param  \Laravel\Ai\Contracts\Tool[]  $tools
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
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
    ): TextResponse;

    /**
     * Stream text representing the next message in a conversation.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     * @param  \Laravel\Ai\Contracts\Tool[]  $tools
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
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
    ): Generator;

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self;
}
