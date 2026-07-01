<?php

namespace Laravel\Ai\Scheduling;

use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Arr;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Throwable;

/**
 * @mixin CallbackEvent
 */
class PendingScheduledAgent
{
    protected CallbackEvent $event;

    protected array $then = [];

    protected array $catch = [];

    /**
     * Set the underlying scheduled event.
     */
    public function setEvent(CallbackEvent $event): static
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Register a callback to run once the agent's response is available.
     */
    public function then(Closure $callback): static
    {
        $this->then[] = $callback;

        return $this;
    }

    /**
     * Register a callback to run if the agent fails.
     */
    public function catch(Closure $callback): static
    {
        $this->catch[] = $callback;

        return $this;
    }

    /**
     * Send the agent's output to a given file.
     */
    public function sendOutputTo(string $path, bool $append = false): static
    {
        return $this->then(static function (AgentResponse $response) use ($path, $append) {
            file_put_contents($path, $response->text.PHP_EOL, $append ? FILE_APPEND : 0);
        });
    }

    /**
     * Append the agent's output to a given file.
     */
    public function appendOutputTo(string $path): static
    {
        return $this->sendOutputTo($path, true);
    }

    /**
     * E-mail the agent's output to the given addresses.
     */
    public function emailOutputTo(array|string $addresses, bool $onlyIfOutputExists = true): static
    {
        $addresses = Arr::wrap($addresses);

        return $this->then(static function (AgentResponse $response) use ($addresses, $onlyIfOutputExists) {
            if ($onlyIfOutputExists && trim($response->text) === '') {
                return;
            }

            app(Mailer::class)->raw($response->text, function ($message) use ($addresses) {
                $message->to($addresses)->subject('Scheduled Agent Output');
            });
        });
    }

    /**
     * E-mail the agent's failure to the given addresses.
     */
    public function emailOutputOnFailure(array|string $addresses): static
    {
        $addresses = Arr::wrap($addresses);

        return $this->catch(static function (Throwable $e) use ($addresses) {
            app(Mailer::class)->raw($e->getMessage(), function ($message) use ($addresses) {
                $message->to($addresses)->subject('Scheduled Agent Failed');
            });
        });
    }

    /**
     * Attach the registered callbacks to the queued agent response.
     */
    public function report(QueuedAgentResponse $response): void
    {
        foreach ($this->then as $callback) {
            $response->then($callback);
        }

        foreach ($this->catch as $callback) {
            $response->catch($callback);
        }
    }

    /**
     * Get the registered "then" callbacks.
     */
    public function thenCallbacks(): array
    {
        return $this->then;
    }

    /**
     * Get the registered "catch" callbacks.
     */
    public function catchCallbacks(): array
    {
        return $this->catch;
    }

    /**
     * Proxy scheduler method calls to the underlying event.
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->event->{$method}(...$parameters);

        return $result === $this->event ? $this : $result;
    }

    /**
     * Proxy property access to the underlying event.
     */
    public function __get(string $name)
    {
        return $this->event->{$name};
    }
}
