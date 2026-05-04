<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesEmbeddableAudio
{
    use SerializesEmbeddedContent;

    protected function defaultMimeType(): string
    {
        return 'audio/mpeg';
    }
}
