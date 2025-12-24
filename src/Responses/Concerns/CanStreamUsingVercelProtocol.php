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
        $state = new class {
            public $streamStarted = false;
        };

        return response()->stream(function () use ($state) {
            $lastStreamEndEvent = null;

            foreach ($this as $event) {
                if ($event instanceof StreamStart) {
                    if ($state->streamStarted) {
                        continue;
                    }

                    $state->streamStarted = true;
                }

                if ($event instanceof StreamEnd) {
                    $lastStreamEndEvent = $event;

                    continue;
                }

                if (empty($data = $event->toVercelProtocolArray())) {
                    continue;
                }

                yield 'data: '.json_encode($data)."\n\n";
            }

            if ($lastStreamEndEvent) {
                yield 'data: '.json_encode($lastStreamEndEvent->toVercelProtocolArray())."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }
}
