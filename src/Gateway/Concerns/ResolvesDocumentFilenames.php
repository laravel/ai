<?php

namespace Laravel\Ai\Gateway\Concerns;

trait ResolvesDocumentFilenames
{
    /**
     * Derive a filename for a nameless document from its MIME type.
     */
    protected function fallbackFilename(?string $mimeType): string
    {
        return 'document'.match ($mimeType) {
            'text/plain' => '.txt',
            'text/markdown' => '.md',
            'text/csv' => '.csv',
            'text/html' => '.html',
            'application/pdf' => '.pdf',
            'application/json' => '.json',
            default => '',
        };
    }
}
