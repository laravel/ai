<?php

namespace Laravel\Ai\Responses\Concerns;

use Generator;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait CanStreamUsingVercelProtocol
{
    /**
     * Create an HTTP response that represents the object using the Vercel AI SDK protocol
     *
     * @return Response
     */
    protected function toVercelProtocolResponse()
    {
        $state = new class
        {
            public bool $streamStarted = false;

            public array $toolCalls = [];

            public ?StreamEnd $lastStreamEnd = null;

            public ?Usage $usage = null;

            public bool $errored = false;
        };

        return response()->stream(function () use ($state) {
            try {
                foreach ($this as $event) {
                    // Send one stream start event, wrapping each subsequent provider step in step parts...
                    if ($event instanceof StreamStart && $state->streamStarted) {
                        yield $this->toVercelProtocolFormat(['type' => 'finish-step']);
                        yield $this->toVercelProtocolFormat(['type' => 'start-step']);

                        continue;
                    }

                    // Track tool calls initiated within this stream.
                    if ($event instanceof ToolCall) {
                        $state->toolCalls[$event->toolCall->id] = true;
                    }

                    if ($event instanceof Error) {
                        $state->errored = true;
                    }

                    // A result without a local call is valid only when continuing the client message that contains the call...
                    if ($event instanceof ToolResult
                        && ! isset($state->toolCalls[$event->toolResult->id])
                        && $this->vercelProtocolMessageId === null) {
                        continue;
                    }

                    if ($event instanceof ToolApprovalRequest) {
                        foreach ($event->pendingApprovals as $pendingApproval) {
                            yield from $this->toVercelProtocolPart($state, [
                                'type' => 'tool-approval-request',
                                'toolCallId' => $pendingApproval->id,
                                'approvalId' => $pendingApproval->id,
                                'reason' => $pendingApproval->reason,
                            ]);
                        }

                        continue;
                    }

                    // Save the last stream end event until the very end, combining usage across steps...
                    if ($event instanceof StreamEnd) {
                        $state->lastStreamEnd = $event;
                        $state->usage = ($state->usage ?? new Usage)->add($event->usage);

                        continue;
                    }

                    if (empty($data = $event->toVercelProtocolArray())) {
                        continue;
                    }

                    yield from $this->toVercelProtocolPart($state, $data);
                }

                if ($state->streamStarted && ! $state->errored) {
                    yield $this->toVercelProtocolFormat(['type' => 'finish-step']);

                    if ($state->lastStreamEnd) {
                        yield $this->toVercelProtocolFormat((new StreamEnd(
                            $state->lastStreamEnd->id,
                            $state->lastStreamEnd->reason,
                            $state->usage,
                            $state->lastStreamEnd->timestamp,
                        ))->toVercelProtocolArray());
                    }
                }
            } catch (Throwable $e) {
                // A stream error exception carries a provider error the stream already surfaced, so only report anything else...
                if (! $e instanceof StreamErrorException) {
                    report($e);
                }

                // The response is already streaming, so surface a masked terminal error part instead of re-throwing, unless an error part was already sent...
                if (! $state->errored) {
                    yield from $this->toVercelProtocolPart($state, [
                        'type' => 'error',
                        'errorText' => 'An error occurred.',
                    ]);
                }
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    /**
     * Encode the given protocol part, preceded by a start part when one has not been sent yet.
     */
    protected function toVercelProtocolPart(object $state, array $data): Generator
    {
        if ($data['type'] === 'start') {
            $state->streamStarted = true;

            $data['messageId'] = $this->vercelProtocolMessageId ?? $data['messageId'];

            yield $this->toVercelProtocolFormat($data);
            yield $this->toVercelProtocolFormat(['type' => 'start-step']);

            return;
        }

        if (! $state->streamStarted) {
            yield from $this->toVercelProtocolPart($state, ['type' => 'start', 'messageId' => $this->invocationId]);
        }

        yield $this->toVercelProtocolFormat($data);
    }

    /**
     * Encode the given protocol part as a server-sent event line.
     */
    protected function toVercelProtocolFormat(array $data): string
    {
        return 'data: '.json_encode($data)."\n\n";
    }
}
