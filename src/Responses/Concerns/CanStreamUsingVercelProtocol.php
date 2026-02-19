<?php

namespace Laravel\Ai\Responses\Concerns;

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

trait CanStreamUsingVercelProtocol
{
    /**
     * Create an HTTP response that represents the object using the Vercel AI SDK protocol
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function toVercelProtocolResponse()
    {
        $state = new class
        {
            public bool $streamStarted = false;

            public array $toolCalls = [];

            public ?array $lastStreamEndEvent = null;
        };

        $stream = function () use ($state): iterable {
            $lastStreamEndEvent = null;

            foreach ($this as $event) {
                // Send one stream start event...
                if ($event instanceof StreamStart) {
                    if ($state->streamStarted) {
                        continue;
                    }

                    $state->streamStarted = true;
                }

                // Store initiated tool calls...
                if ($event instanceof ToolCall) {
                    $state->toolCalls[$event->toolCall->id] = true;
                }

                // Skip tool results if no prior associated tool call...
                if ($event instanceof ToolResult &&
                    ! isset($state->toolCalls[$event->toolResult->id])) {
                    continue;
                }

                // Save the last stream end event until the very end...
                if ($event instanceof StreamEnd) {
                    $state->lastStreamEndEvent = $event->toVercelProtocolArray();

                    continue;
                }

                if (empty($data = $event->toVercelProtocolArray())) {
                    continue;
                }

                yield 'data: '.json_encode($data)."\n\n";
            }

            if ($state->lastStreamEndEvent) {
                yield 'data: '.json_encode($state->lastStreamEndEvent)."\n\n";
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
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }
}
