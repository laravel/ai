<?php

namespace Laravel\Ai\Gateway\Concerns;

use Generator;

trait ParsesServerSentEvents
{
    /**
     * Parse an SSE stream body into decoded JSON data objects.
     */
    protected function parseServerSentEvents($streamBody): Generator
    {
        while (! $streamBody->eof()) {
            $line = trim($this->readLine($streamBody));

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                return;
            }

            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                yield $decoded;
            }
        }
    }

    /**
     * Read a single line from the stream, byte by byte, to prevent event batching.
     */
    protected function readLine($streamBody): string
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $byte = $streamBody->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
