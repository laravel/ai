<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use IteratorAggregate;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * @implements IteratorAggregate<int, StreamEvent>
 */
class StepResult implements IteratorAggregate
{
    /** @var array<int, Closure(StepResponse): void> */
    protected array $callbacks = [];

    protected ?StepResponse $response = null;

    protected bool $resolved = false;

    /** @var StreamEvent[] */
    protected array $buffered = [];

    /**
     * @param  Generator<int, StreamEvent, mixed, StepResponse|null>|StepResponse  $source
     * @param  PendingStep|null  $step  The step as sent to the model; null when middleware supplied the response itself.
     */
    public function __construct(
        protected Generator|StepResponse $source,
        public readonly ?PendingStep $step = null,
        public readonly ?StepContext $context = null,
        public readonly ?int $startedAt = null,
    ) {}

    /**
     * Register a callback to run once the step's response is available.
     *
     * @param  Closure(StepResponse): void  $callback
     */
    public function then(Closure $callback): static
    {
        if (! $this->resolved) {
            $this->callbacks[] = $callback;
        } elseif ($this->response instanceof StepResponse) {
            $callback($this->response);
        }

        return $this;
    }

    /**
     * @return Generator<int, StreamEvent>
     */
    public function getIterator(): Generator
    {
        if ($this->source instanceof StepResponse) {
            $this->resolved || $this->resolve($this->source);

            return;
        }

        yield from $this->buffered;

        if ($this->resolved) {
            $this->buffered = [];

            return;
        }

        // An abandoned iteration leaves the source parked on the event it last yielded...
        if ($this->buffered !== [] && $this->source->current() === end($this->buffered)) {
            $this->source->next();
        }

        while ($this->source->valid()) {
            $this->buffered[] = $event = $this->source->current();

            yield $event;

            $this->source->next();
        }

        $this->resolve($this->source->getReturn());
    }

    public function streamed(): bool
    {
        return $this->source instanceof Generator;
    }

    /**
     * The step's response, or null when a stream ended without one.
     */
    public function response(): ?StepResponse
    {
        if (! $this->resolved) {
            iterator_to_array($this, false);
        }

        return $this->response;
    }

    protected function resolve(mixed $response): void
    {
        $this->resolved = true;

        if (! $response instanceof StepResponse) {
            return;
        }

        $this->response = $response;

        foreach ($this->callbacks as $callback) {
            $callback($response);
        }
    }
}
