<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesAudioContent
{
    use SerializesInlineContent;

    protected function defaultMimeType(): string
    {
        return 'audio/mpeg';
    }
}
