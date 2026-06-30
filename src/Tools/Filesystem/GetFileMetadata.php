<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;
use Throwable;

#[Strict]
class GetFileMetadata extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Get metadata for a file (size in bytes, last modified time, MIME type, visibility) without reading its contents. Use ReadFile to read the contents.';
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

        return json_encode([
            'path' => $path,
            'size' => $size,
            'last_modified' => rescue(fn () => $disk->lastModified($path), report: false),
            'mime_type' => rescue(fn () => $disk->mimeType($path), report: false),
            'visibility' => rescue(fn () => $disk->getVisibility($path), report: false),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to inspect, relative to the disk root.')
                ->required(),
        ];
    }
}
