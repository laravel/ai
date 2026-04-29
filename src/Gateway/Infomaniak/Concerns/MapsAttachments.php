<?php

namespace Laravel\Ai\Gateway\Infomaniak\Concerns;

use Laravel\Ai\Contracts\Files\HasName;
use Laravel\Ai\Files\File;

trait MapsAttachments
{
    protected function mapAttachments(array $attachments): array
    {
        return array_map(fn (File $file) => match (true) {
            $file instanceof HasName => ['type' => 'file', 'file' => ['file_name' => $file->name(), 'mime_type' => $file->mime()]],
            default => ['type' => 'file', 'file' => ['file_name' => 'file', 'mime_type' => 'application/octet-stream']],
        }, $attachments);
    }
}
