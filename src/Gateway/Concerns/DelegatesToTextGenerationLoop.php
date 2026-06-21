<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

trait DelegatesToTextGenerationLoop
{
    protected ?TextGenerationLoop $textGenerationLoop = null;

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->textGenerationLoop()->onToolInvocation($invoking, $invoked);

        return $this;
    }

    /**
     * Generate text by delegating multi-step orchestration to the text generation loop.
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
    ): TextResponse {
        return $this->textGenerationLoop()->generate(
            $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout,
        );
    }

    /**
     * Stream text by delegating multi-step orchestration to the text generation loop.
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
    ): Generator {
        yield from $this->textGenerationLoop()->stream(
            $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout,
        );
    }

    /**
     * Get the shared text generation loop instance for this gateway.
     */
    protected function textGenerationLoop(): TextGenerationLoop
    {
        return $this->textGenerationLoop ??= new TextGenerationLoop($this);
    }
}
