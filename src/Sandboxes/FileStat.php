<?php

namespace Laravel\Ai\Sandboxes;

class FileStat
{
    public function __construct(
        public readonly bool $isFile,
        public readonly bool $isDirectory,
        public readonly int $size = 0,
        public readonly ?int $mtime = null,
    ) {}
}
