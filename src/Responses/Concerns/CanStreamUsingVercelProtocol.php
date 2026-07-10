<?php

namespace Laravel\Ai\Responses\Concerns;

use Generator;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
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
        if ($data['type'] === 'start') {
            $data['messageId'] = $this->vercelProtocolMessageId ?? $data['messageId'];
        } elseif (! $state->streamStarted) {
            $state->streamStarted = true;

            yield 'data: '.json_encode(['type' => 'start', 'messageId' => $this->vercelProtocolMessageId ?? $this->invocationId])."\n\n";
        }

        yield 'data: '.json_encode($data)."\n\n";
    }
}
