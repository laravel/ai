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
        $buffer = '';

        while (! $streamBody->eof()) {
            $buffer .= $streamBody->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (! str_starts_with($line, 'data:')) {
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
    }
}
