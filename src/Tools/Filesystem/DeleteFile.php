<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;

#[Strict]
class DeleteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Permanently delete a file from the filesystem disk. This cannot be undone.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $path = (string) $request->string('path');
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return "File [{$path}] does not exist.";
        }

        $disk->delete($path);

        return "Deleted [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to delete, relative to the disk root.')
                ->required(),
        ];
    }
}
