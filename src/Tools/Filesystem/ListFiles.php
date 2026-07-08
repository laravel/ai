<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;

#[Strict]
class ListFiles extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'List the files and directories directly within a path on the filesystem disk. Returns a JSON object with separate "directories" and "files" arrays of paths.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $path = $request->string('path');

        return json_encode([
            'path' => $path,
            'directories' => $request->boolean('recursive')
                ? $disk->allDirectories($path)
                : $disk->directories($path),
            'files' => $request->boolean('recursive')
                ? $disk->allFiles($path)
                : $disk->files($path),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The directory path to list, relative to the disk root. Use an empty string to list the disk root.')
                ->required(),
            'recursive' => $schema->boolean()
                ->description('When true, also include files and directories within all nested subdirectories.')
                ->required(),
        ];
    }
}
