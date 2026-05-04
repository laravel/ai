<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesEmbeddableImage
{
    use SerializesEmbeddedContent;

    protected function defaultMimeType(): string
    {
        return 'image/png';
    }
}
