<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;

abstract class FilesystemTool implements Tool
{
    public function __construct(protected string|Filesystem|null $disk = null) {}

    /**
     * Resolve the filesystem disk the tool operates on.
     */
    protected function disk(): Filesystem
    {
        return $this->disk instanceof Filesystem
            ? $this->disk
            : Storage::disk($this->disk);
    }
}
