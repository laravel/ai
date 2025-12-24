<?php

namespace Laravel\Ai\Responses;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Traversable;

class StreamableAgentResponse implements IteratorAggregate, Responsable
{
    use Concerns\CanStreamUsingVercelProtocol;

    public ?string $text;

    public ?Usage $usage;

    public Collection $events;

    protected array $thenCallbacks = [];

    protected bool $usesVercelProtocol = false;

    public function __construct(public string $invocationId, protected Closure $generator)
    {
        $this->events = new Collection;
    }

    /**
     * Execute a callback over each event.
     */
    public function each(callable $callback): self
    {
        foreach ($this as $event) {
            if ($callback($event) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Provide a callback that should be invoked when the stream completes.
     */
    public function then(callable $callback): self
    {
        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Stream the response using Vercel's AI SDK stream protocol.
     *
     * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
     */
    public function usingVercelProtocol(bool $value = true): self
    {
        $this->usesVercelProtocol = $value;

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        if ($this->usesVercelProtocol) {
            return $this->toVercelProtocolResponse();
        }

        return response()->stream(function () {
            foreach ($this as $event) {
                yield (string) $event;
            }
        });
    }

    /**
     * Get an iterator for the object.
     */
    public function getIterator(): Traversable
    {
        // Use existing events if we've already streamed them once...
        if (count($this->events) > 0) {
            foreach ($this->events as $event) {
                yield $event;
            }

            return;
        }

        $events = [];

        // Resolve the stream of the prompt and yield the events...
        foreach (call_user_func($this->generator) as $event) {
            $events[] = $event;

            yield $event;
        }

        $this->events = new Collection($events);
        $this->text = TextDelta::combine($events);
        $this->usage = StreamEnd::combineUsage($events);

        foreach ($this->thenCallbacks as $callback) {
            call_user_func($callback, $this);
        }
    }
}
