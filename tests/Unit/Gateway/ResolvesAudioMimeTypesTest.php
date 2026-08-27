<?php

use Laravel\Ai\Gateway\Concerns\ResolvesAudioMimeTypes;

function callAudioResponseMimeType(string $format): string
{
    $class = new class
    {
        use ResolvesAudioMimeTypes;

        public function getMimeType(string $format): string
        {
            return $this->audioResponseMimeType($format);
        }
    };

    return $class->getMimeType($format);
}

test('audio response mime type is correctly mapped from format', function (string $format, string $expectedMimeType): void {
    expect(callAudioResponseMimeType($format))->toBe($expectedMimeType);
})->with([
    ['mp3', 'audio/mpeg'],
    ['MP3', 'audio/mpeg'],
    ['mp3_44100_128', 'audio/mpeg'],
    ['wav', 'audio/wav'],
    ['pcm', 'audio/pcm'],
    ['pcm_44100', 'audio/pcm'],
    ['opus', 'audio/opus'],
    ['opus_16000', 'audio/opus'],
    ['aac', 'audio/aac'],
    ['aac_16000', 'audio/aac'],
    ['flac', 'audio/flac'],
    ['unknown_format', 'audio/mpeg'],
]);
