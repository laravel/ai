<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

/**
 * The using class must expose a `protected Dispatcher $events` property.
 *
 * @property Dispatcher $events
 */
trait DelegatesToTextGenerationLoop
{
    protected ?Closure $invokingToolCallback = null;

    protected ?Closure $toolInvokedCallback = null;

    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

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
        return $this->buildTextGenerationLoop()->generate(
            $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout,
        );
    }

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
        yield from $this->buildTextGenerationLoop()->stream(
            $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout,
        );
    }

    protected function buildTextGenerationLoop(): TextGenerationLoop
    {
        $loop = new TextGenerationLoop($this, $this->events);

        if ($this->invokingToolCallback !== null && $this->toolInvokedCallback !== null) {
            $loop->onToolInvocation($this->invokingToolCallback, $this->toolInvokedCallback);
        }

        return $loop;
    }
}
