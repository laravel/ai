<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use IteratorAggregate;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * The outcome of a generation step as seen by middleware: iterable while streaming, resolved to a StepResponse once the model has answered.
 *
 * @implements IteratorAggregate<int, StreamEvent>
 */
class StepResult implements IteratorAggregate
{
    /** @var array<int, Closure(StepResponse): void> */
    protected array $callbacks = [];

    protected ?StepResponse $response = null;

    protected bool $resolved = false;

    /** @var StreamEvent[] Events drained by response() before the stream was iterated, replayed by getIterator(). */
    protected array $buffered = [];

    /**
     * @param  Generator<int, StreamEvent, mixed, StepResponse|null>|StepResponse  $source
     */
    public function __construct(protected Generator|StepResponse $source) {}

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
        if ($this->resolved) {
            yield from $this->buffered;

            $this->buffered = [];

            return;
        }

        if ($this->source instanceof StepResponse) {
            $this->resolve($this->source);

            return;
        }

        yield from $this->source;

        $this->resolve($this->source->getReturn());
    }

    /**
     * The step's response, or null when a stream ended without one.
     */
    public function response(): ?StepResponse
    {
        if (! $this->resolved) {
            $this->buffered = iterator_to_array($this, false);
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
