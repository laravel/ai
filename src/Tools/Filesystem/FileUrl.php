<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;
use Throwable;

#[Strict]
class FileUrl extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Generate a URL for accessing a file on the filesystem disk. Disks that do not support URLs return an explanatory message instead of a URL.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $path = $request->string('path');

        if (! $this->fileExists($disk, $path)) {
            return "File [{$path}] does not exist.";
        }

        $minutes = $request->integer('expires_in_minutes');

        try {
            return $minutes > 0
                ? $disk->temporaryUrl($path, now()->addMinutes($minutes))
                : $disk->url($path);
        } catch (Throwable $e) {
            return "Unable to generate a URL for [{$path}]: {$e->getMessage()}";
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path, relative to the disk root.')
                ->required(),
            'expires_in_minutes' => $schema->integer()
                ->description('Number of minutes a temporary signed URL stays valid, or null for the disk\'s standard (non-expiring) URL.')
                ->nullable()
                ->required(),
        ];
    }
}
