<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasRemoteContent;

class RemoteImage extends Image implements StorableFile
{
    use CanBeUploadedToProvider, HasRemoteContent;

    public function __construct(public string $url, public ?string $mime = null) {}
}
