<?php

namespace Laravel\Ai\Streaming\Protocols;

use Generator;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

abstract class StreamProtocol
{
    protected bool $started = false;

    protected bool $errored = false;

    /**
     * Get the protocol parts that represent the given response's events.
     */
    abstract protected function parts(StreamableAgentResponse $response): Generator;

    /**
     * Get the protocol parts that terminate a stream interrupted by an exception.
     */
    abstract protected function maskedErrorParts(Throwable $exception): Generator;

    /**
     * Get the response headers for the protocol.
     *
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * Create an HTTP response that represents the given response using the protocol.
     */
    public function response(StreamableAgentResponse $response): Response
    {
        return response()->stream(function () use ($response) {
            try {
                foreach ($this->parts($response) as $part) {
                    yield $this->encode($part);
                }
            } catch (Throwable $e) {
                // Stream errors were already surfaced, and a resumable mismatch is a race, so only report anything else...
                if (! $e instanceof StreamErrorException && ! ($e instanceof ApprovalMismatchException && $e->isRecoverable())) {
                    report($e);
                }

                // The response is already streaming, so surface a masked terminal error part instead of re-throwing, unless an error part was already sent...
                if (! $this->errored) {
                    foreach ($this->maskedErrorParts($e) as $part) {
                        yield $this->encode($part);
                    }
                }
            }

            if (($terminator = $this->terminator()) !== null) {
                yield $terminator;
            }
        }, headers: $this->headers());
    }

    /**
     * Get the raw frame that terminates the stream, if any.
     */
    protected function terminator(): ?string
    {
        return null;
    }

    /**
     * Encode the given protocol part as a server-sent event line.
     *
     * @param  array<string, mixed>  $part
     */
    protected function encode(array $part): string
    {
        return 'data: '.$this->json($part)."\n\n";
    }

    /**
     * Encode the given value as JSON, substituting bytes a provider streamed as invalid UTF-8.
     */
    protected function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
