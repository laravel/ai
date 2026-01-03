<?php

namespace Laravel\Ai\Events;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\StoredFileResponse;

class FileStored
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile|UploadedFile|string $file,
        public ?string $mime,
        public StoredFileResponse $response,
    ) {}
}
