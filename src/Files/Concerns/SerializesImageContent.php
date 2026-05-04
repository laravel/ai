<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesImageContent
{
    use SerializesInlineContent;

    protected function defaultMimeType(): string
    {
        return 'image/png';
    }
}
