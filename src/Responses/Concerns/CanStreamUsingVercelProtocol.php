<?php

namespace Laravel\Ai\Responses\Concerns;

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
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
            $lastStreamEndEvent = null;

            foreach ($this as $event) {
                if ($event->isNested()) {
                    $data = $this->nestedEventToVercelDataPart($event);

                    if (! empty($data)) {
                        yield 'data: '.json_encode($data)."\n\n";
                    }

                    continue;
                }

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
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    /**
     * Convert nested tool/sub-agent activity to a Vercel custom data part.
     */
    protected function nestedEventToVercelDataPart(StreamEvent $event): array
    {
        $payload = $event->toArray();

        unset($payload['id'], $payload['invocation_id'], $payload['timestamp']);

        return [
            'type' => 'data-subagent',
            'id' => $event->nestedVercelPartId(),
            'data' => [
                ...$payload,
                'parent_invocation_id' => $event->parentInvocationId,
                'parent_tool_call_id' => $event->parentToolCallId,
                'ancestor_tool_call_ids' => $event->ancestorToolCallIds,
                'depth' => $event->depth(),
            ],
        ];
    }
}
