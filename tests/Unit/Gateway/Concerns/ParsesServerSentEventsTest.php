<?php

use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;

function parser(): object
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

test('reads stream byte by byte to prevent event batching', function () {
    $payload = implode("\n\n", [
        'data: {"type":"message_start"}',
        'data: {"type":"content_block_delta","delta":{"text":"Hello"}}',
        'data: {"type":"content_block_delta","delta":{"text":" world"}}',
        'data: {"type":"message_delta"}',
    ])."\n\n";

    $stream = new FakeStream($payload);

    iterator_to_array(parser()->parse($stream));

    expect($stream->maxReadSize)->toBe(1, 'Parser must read byte-by-byte to prevent SSE event batching.');
});

test('yields each event before reading next events data', function () {
    $payload = "data: {\"type\":\"event_1\"}\n\ndata: {\"type\":\"event_2\"}\n\ndata: {\"type\":\"event_3\"}\n\n";

    $stream = new FakeStream($payload);
    $generator = parser()->parse($stream);

    $first = $generator->current();
    expect($first['type'])->toBe('event_1');
    expect($stream->fullyConsumed())->toBeFalse('Events must be yielded progressively, not after buffering the entire stream.');

    $generator->next();
    $second = $generator->current();
    expect($second['type'])->toBe('event_2');
    expect($stream->fullyConsumed())->toBeFalse('Stream should not be fully consumed after yielding only two events.');

    $generator->next();
    $third = $generator->current();
    expect($third['type'])->toBe('event_3');
});

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
