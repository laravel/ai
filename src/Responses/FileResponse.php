<?php

namespace Laravel\Ai\Responses;

class FileResponse
{
    public function __construct(
        public string $id,
        public ?string $mime = null,
        public ?string $content = null,
    ) {}
}
