<?php

namespace Laravel\Ai\Responses\Concerns;

use Generator;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;

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

            public ?array $lastStreamEndEvent = null;
        };

        return response()->stream(function () use ($state) {
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

                // A resumed approval's tool call streamed in a prior response, so replay its input before its output so the client can associate the two...
                if ($event instanceof ToolResult &&
                    ! isset($state->toolCalls[$event->toolResult->id])) {
                    $state->toolCalls[$event->toolResult->id] = true;

                    yield from $this->toVercelProtocolPart($state, [
                        'type' => 'tool-input-available',
                        'toolCallId' => $event->toolResult->id,
                        'toolName' => $event->toolResult->name,
                        'input' => $event->toolResult->arguments,
                    ]);
                }

                // Surface each pending approval so the client may render an approval prompt...
                if ($event instanceof ToolApprovalRequest) {
                    foreach ($event->pendingApprovals as $pendingApproval) {
                        yield from $this->toVercelProtocolPart($state, [
                            'type' => 'tool-approval-request',
                            'toolCallId' => $pendingApproval->id,
                            'approvalId' => $pendingApproval->id,
                        ]);
                    }

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

                yield from $this->toVercelProtocolPart($state, $data);
            }

            if ($state->lastStreamEndEvent) {
                yield 'data: '.json_encode($state->lastStreamEndEvent)."\n\n";
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
        // Resuming from an approval yields tool parts before the provider's stream start...
        if (! $state->streamStarted && $data['type'] !== 'start') {
            $state->streamStarted = true;

            yield 'data: '.json_encode(['type' => 'start', 'messageId' => $this->invocationId])."\n\n";
        }

        yield 'data: '.json_encode($data)."\n\n";
    }
}
