<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesEmbeddableDocument
{
    use SerializesEmbeddedContent;

    protected function defaultMimeType(): string
    {
        return 'application/octet-stream';
    }
}
