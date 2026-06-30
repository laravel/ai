<?php

use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;

function decoderHost(): object
{
    return new class
    {
        use DecodesStructuredOutput;

        public function decode(?string $text): array
        {
            return $this->decodeStructuredOutput($text);
        }
    };
}

test('decodes plain JSON object', function () {
    $host = decoderHost();

    expect($host->decode('{"name":"Ada","age":36}'))
        ->toBe(['name' => 'Ada', 'age' => 36]);
});

test('strips ```json fence and decodes', function () {
    $host = decoderHost();

    $payload = "```json\n{\"ok\":true}\n```";

    expect($host->decode($payload))->toBe(['ok' => true]);
});

test('strips bare ``` fence and decodes', function () {
    $host = decoderHost();

    $payload = "```\n{\"ok\":true}\n```";

    expect($host->decode($payload))->toBe(['ok' => true]);
});

test('tolerates leading and trailing whitespace around the fence', function () {
    $host = decoderHost();

    $payload = "  \n\n```json\n{\"value\":42}\n```  \n";

    expect($host->decode($payload))->toBe(['value' => 42]);
});

test('handles uppercase JSON fence language tag', function () {
    $host = decoderHost();

    $payload = "```JSON\n{\"ok\":true}\n```";

    expect($host->decode($payload))->toBe(['ok' => true]);
});

test('returns empty array for invalid JSON', function () {
    $host = decoderHost();

    expect($host->decode('not json at all'))->toBe([])
        ->and($host->decode("```json\n{invalid}\n```"))->toBe([]);
});

test('returns empty array for null or empty input', function () {
    $host = decoderHost();

    expect($host->decode(null))->toBe([])
        ->and($host->decode(''))->toBe([])
        ->and($host->decode('   '))->toBe([]);
});

test('does not mangle JSON containing triple-backtick substrings inside string values', function () {
    $host = decoderHost();

    $payload = '{"snippet":"```js\nconsole.log(1)\n```","ok":true}';

    expect($host->decode($payload))->toBe([
        'snippet' => "```js\nconsole.log(1)\n```",
        'ok' => true,
    ]);
});

test('returns empty array when JSON decodes to a non-array scalar', function () {
    $host = decoderHost();

    expect($host->decode('"just a string"'))->toBe([])
        ->and($host->decode('42'))->toBe([]);
});
