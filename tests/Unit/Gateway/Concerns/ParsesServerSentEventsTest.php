<?php

namespace Tests\Unit\Gateway\Concerns;

use Generator;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use PHPUnit\Framework\TestCase;

class ParsesServerSentEventsTest extends TestCase
{
    protected function parser(): object
    {
        return new class
        {
            use ParsesServerSentEvents;

            public function parse($streamBody): Generator
            {
                return $this->parseServerSentEvents($streamBody);
            }
        };
    }

    public function test_reads_stream_byte_by_byte_to_prevent_event_batching(): void
    {
        $payload = implode("\n\n", [
            'data: {"type":"message_start"}',
            'data: {"type":"content_block_delta","delta":{"text":"Hello"}}',
            'data: {"type":"content_block_delta","delta":{"text":" world"}}',
            'data: {"type":"message_delta"}',
        ])."\n\n";

        $stream = new FakeStream($payload);

        iterator_to_array($this->parser()->parse($stream));

        $this->assertSame(1, $stream->maxReadSize, 'Parser must read byte-by-byte to prevent SSE event batching.');
    }

    public function test_yields_each_event_before_reading_next_events_data(): void
    {
        $payload = "data: {\"type\":\"event_1\"}\n\ndata: {\"type\":\"event_2\"}\n\ndata: {\"type\":\"event_3\"}\n\n";

        $stream = new FakeStream($payload);
        $generator = $this->parser()->parse($stream);

        $first = $generator->current();
        $this->assertSame('event_1', $first['type']);
        $this->assertFalse($stream->fullyConsumed(), 'Events must be yielded progressively, not after buffering the entire stream.');

        $generator->next();
        $second = $generator->current();
        $this->assertSame('event_2', $second['type']);
        $this->assertFalse($stream->fullyConsumed(), 'Stream should not be fully consumed after yielding only two events.');

        $generator->next();
        $third = $generator->current();
        $this->assertSame('event_3', $third['type']);
    }
}

/**
 * A fake PSR-7-compatible stream that tracks read behavior.
 */
class FakeStream
{
    private int $position = 0;

    public int $maxReadSize = 0;

    public function __construct(private readonly string $data) {}

    public function read(int $length): string
    {
        if ($length > $this->maxReadSize) {
            $this->maxReadSize = $length;
        }

        $chunk = substr($this->data, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function fullyConsumed(): bool
    {
        return $this->position >= strlen($this->data);
    }
}
