<?php

namespace Laravel\Ai\Responses;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Laravel\Ai\Responses\Data\Citation as CitationData;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\Response;
use Traversable;

class StreamableAgentResponse implements IteratorAggregate, Responsable
{
    use Concerns\CanStreamUsingVercelProtocol;

    public ?string $text = null;

    public ?Usage $usage = null;

    /** @var Collection<int, StreamEvent> */
    public Collection $events;

    /** @var Collection<int, CitationData> */
    public Collection $citations;

    public ?string $conversationId = null;

    public ?object $conversationUser = null;

    protected array $thenCallbacks = [];

    protected bool $usesVercelProtocol = false;

    protected ?string $vercelProtocolMessageId = null;

    protected ?StreamedAgentResponse $streamedResponse = null;

    protected bool $hasYielded = false;

    /**
     * Create a new streamable agent response instance.
     */
    public function __construct(
        public string $invocationId,
        protected Closure $generator,
        protected ?Meta $meta = null,
    ) {
        $this->events = new Collection;
        $this->citations = new Collection;
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
        if ($this->streamedResponse instanceof StreamedAgentResponse) {
            $callback($this->streamedResponse);

            $this->syncConversationFromStreamedResponse();

            return $this;
        }

        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the conversation UUID for this response.
     */
    public function withinConversation(?string $conversationId, ?object $conversationUser = null): self
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Adopt state from a completed streamed response.
     */
    public function adoptStateFrom(StreamedAgentResponse $response): self
    {
        if ($this->meta instanceof Meta) {
            $this->meta->provider = $response->meta->provider;
            $this->meta->model = $response->meta->model;
            $this->meta->citations = $response->meta->citations;
        }

        if ($response->conversationId !== null) {
            $this->withinConversation($response->conversationId, $response->conversationUser);
        }

        return $this;
    }

    /**
     * Stream the response using Vercel's AI SDK stream protocol.
     *
     * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
     */
    public function usingVercelDataProtocol(bool $value = true, ?string $messageId = null): self
    {
        $this->usesVercelProtocol = $value;
        $this->vercelProtocolMessageId = $messageId;

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($this->usesVercelProtocol) {
            return $this->toVercelProtocolResponse();
        }

        return response()->stream(function () {
            foreach ($this as $event) {
                yield 'data: '.($event)."\n\n";
            }

            yield "data: [DONE]\n\n";
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
                $this->hasYielded = true;

                yield $event;
            }

            return;
        }

        $events = [];

        // Resolve the stream of the prompt and yield the events...
        foreach (call_user_func($this->generator) as $event) {
            $events[] = $event;

            $this->hasYielded = true;

            yield $event;
        }

        $this->events = new Collection($events);
        $this->text = TextDelta::combine($events);
        $this->citations = Citation::combine($events);
        $this->usage = StreamEnd::combineUsage($events);

        $this->streamedResponse = new StreamedAgentResponse(
            $this->invocationId,
            $this->events,
            $this->meta,
        );

        if ($this->conversationId !== null) {
            $this->streamedResponse->withinConversation(
                $this->conversationId,
                $this->conversationUser
            );
        }

        foreach ($this->thenCallbacks as $callback) {
            call_user_func($callback, $this->streamedResponse);
        }

        $this->syncConversationFromStreamedResponse();
    }

    /**
     * Synchronize the conversation state from the completed streamed response.
     */
    protected function syncConversationFromStreamedResponse(): void
    {
        if ($this->streamedResponse->conversationId === null) {
            return;
        }

        $this->conversationId = $this->streamedResponse->conversationId;
        $this->conversationUser = $this->streamedResponse->conversationUser;
    }

    /**
     * Determine whether this response has handed at least one event to a consumer.
     */
    public function hasYielded(): bool
    {
        return $this->hasYielded;
    }
}
