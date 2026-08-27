<?php

namespace Laravel\Ai\Gateway\Concerns;

trait ResolvesAudioMimeTypes
{
    /**
     * Map an audio format to the HTTP audio MIME type.
     */
    protected function audioResponseMimeType(string $format): string
    {
        $format = strtolower(explode('_', $format)[0]);

        return match ($format) {
            'mp3' => 'audio/mpeg',
            'pcm' => 'audio/pcm',
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }
}
