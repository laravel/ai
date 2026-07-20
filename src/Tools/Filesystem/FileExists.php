<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;

#[Strict]
class FileExists extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Determine whether a file exists on the filesystem disk.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $path = $request->string('path');

        return $this->fileExists($this->disk(), $path)
            ? "File [{$path}] exists."
            : "File [{$path}] does not exist.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to check, relative to the disk root.')
                ->required(),
        ];
    }
}
