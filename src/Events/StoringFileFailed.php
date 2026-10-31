<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Providers\Provider;
use Throwable;

class StoringFileFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public Throwable $exception,
    ) {}
}
