<?php

namespace Laravel\Ai\Responses;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Traversable;

class StreamableAgentResponse implements IteratorAggregate, Responsable
{
    use Concerns\CanStreamUsingVercelProtocol;

    public ?string $text;

    public ?Usage $usage;

    public Collection $events;

    public ?string $conversationId = null;

    protected array $thenCallbacks = [];

    protected bool $usesVercelProtocol = false;

    protected ?StreamedAgentResponse $streamedResponse = null;

    public function __construct(
        public string $invocationId,
        protected Closure $generator,
        protected ?Meta $meta = null,
    ) {
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
        // If the response has already been iterated / streamed, invoke now...
        if ($this->streamedResponse) {
            $callback($this->streamedResponse);

            return $this;
        }

        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the conversation UUID for this response.
     */
    public function withinConversation(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * Stream the response using Vercel's AI SDK stream protocol.
     *
     * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
     */
    public function usingVercelDataProtocol(bool $value = true): self
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

        $stream = function (): iterable {
            foreach ($this as $event) {
                yield 'data: '.((string) $event)."\n\n";
            }

            yield "data: [DONE]\n\n";
        };

        return response()->stream(function () use ($stream): void {
            $result = $stream();

            if (! is_iterable($result)) {
                return;
            }

            foreach ($result as $message) {
                if (connection_aborted()) {
                    return;
                }

                echo (string) $message;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, headers: ['Content-Type' => 'text/event-stream']);
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

        $this->streamedResponse = new StreamedAgentResponse(
            $this->invocationId,
            $this->events,
            $this->meta,
        );

        foreach ($this->thenCallbacks as $callback) {
            call_user_func($callback, $this->streamedResponse);
        }
    }
}
