<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Providers\Provider;

class StoringFile
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public ?string $mime,
        public ?string $name,
    ) {}
}
