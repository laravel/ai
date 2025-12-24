<?php

namespace Laravel\Ai\Responses\Concerns;

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;

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
            public $streamStarted = false;

            public ?array $lastStreamEndEvent = null;
        };

        return response()->stream(function () use ($state) {
            $lastStreamEndEvent = null;

            foreach ($this as $event) {
                // Send one stream start event...
                if ($event instanceof StreamStart) {
                    if ($state->streamStarted) {
                        continue;
                    }

                    $state->streamStarted = true;
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
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }
}
