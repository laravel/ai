<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;
use Throwable;

#[Strict]
class ReadFile extends FilesystemTool
{
    /**
     * The maximum number of bytes that may be read inline.
     */
    protected const MAX_BYTES = 256 * 1024;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Read and return the UTF-8 text contents of a file on the filesystem disk. Files larger than 256 KB or that are not valid UTF-8 text (such as images or other binary files) are rejected; use FileUrl to access those instead.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $path = $request->string('path');

        try {
            $size = $disk->size($path);
        } catch (Throwable) {
            return "File [{$path}] does not exist.";
        }

        if ($size > static::MAX_BYTES) {
            return "File [{$path}] is too large to read inline. Use FileMetadata or FileUrl instead.";
        }

        $contents = $disk->get($path);

        if ($contents === null || ! mb_check_encoding($contents, 'UTF-8')) {
            return "File [{$path}] appears to be binary and cannot be read as text. Use FileUrl to access it.";
        }

        return $contents;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to read, relative to the disk root.')
                ->required(),
        ];
    }
}
