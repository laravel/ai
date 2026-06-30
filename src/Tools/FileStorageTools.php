<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Filesystem\CopyFile;
use Laravel\Ai\Tools\Filesystem\DeleteFile;
use Laravel\Ai\Tools\Filesystem\FileExists;
use Laravel\Ai\Tools\Filesystem\GetFileMetadata;
use Laravel\Ai\Tools\Filesystem\GetFileUrl;
use Laravel\Ai\Tools\Filesystem\ListFiles;
use Laravel\Ai\Tools\Filesystem\ReadFile;
use Laravel\Ai\Tools\Filesystem\WriteFile;

class FileStorageTools
{
    /**
     * Create all of the file storage tools for the given disk.
     *
     * @return Collection<int, Tool>
     */
    public static function all(string|Filesystem|null $disk = null): Collection
    {
        return static::readOnly($disk)->merge([
            new WriteFile($disk),
            new DeleteFile($disk),
            new CopyFile($disk),
        ]);
    }

    /**
     * Create the read-only file storage tools for the given disk.
     *
     * @return Collection<int, Tool>
     */
    public static function readOnly(string|Filesystem|null $disk = null): Collection
    {
        return new Collection([
            new ListFiles($disk),
            new ReadFile($disk),
            new FileExists($disk),
            new GetFileMetadata($disk),
            new GetFileUrl($disk),
        ]);
    }
}
