<?php

namespace Laravel\Ai\Gateway\Infomaniak\Concerns;

use Laravel\Ai\Files\File;

trait MapsAttachments
{
    protected function mapAttachments(array $attachments): array
    {
        return array_map(fn (File $file) => [
            'type' => 'file',
            'file' => [
                'file_name' => $file->name(),
                'mime_type' => $file->mime ?? 'application/octet-stream',
            ],
        ], $attachments);
    }
}
