<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesDocumentContent
{
    use SerializesInlineContent;

    protected function defaultMimeType(): string
    {
        return 'application/octet-stream';
    }
}
