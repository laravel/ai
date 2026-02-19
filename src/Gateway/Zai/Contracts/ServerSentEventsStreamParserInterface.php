<?php

namespace Laravel\Ai\Gateway\Zai\Contracts;

use Generator;

interface ServerSentEventsStreamParserInterface
{
    /**
     * Parse Server-Sent Events (SSE) formatted data.
     *
     * @param string $sseData
     * @return Generator<array<string, mixed>>
     */
    public function parse(string $sseData): Generator;
}
