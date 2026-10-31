<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Providers\Provider;
use Throwable;

class AddingFileToStoreFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $fileId,
        public Throwable $exception,
    ) {}
}
