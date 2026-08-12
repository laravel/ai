<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Contracts\Files\HasName;
use Laravel\Ai\Contracts\Files\TranscribableAudio;

trait ResolvesAudioFilenames
{
    /**
     * Determine the appropriate filename for the audio file based on its MIME type.
     */
    protected function audioFilename(TranscribableAudio $audio): string
    {
        if ($audio instanceof HasName && $audio->name()) {
            return $audio->name();
        }

        $extension = match ($audio->mimeType()) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mpga' => 'mpga',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }
}
