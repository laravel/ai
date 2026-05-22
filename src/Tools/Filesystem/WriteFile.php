<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;

#[Strict]
class WriteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Write UTF-8 text contents to a file on the filesystem disk, creating it (and any parent directories) or overwriting it if it already exists.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $path = $request->string('path');
        $contents = $request->string('contents');

        $this->disk()->put($path, $contents);

        return 'Wrote '.strlen($contents)." bytes to [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to write to, relative to the disk root.')
                ->required(),
            'contents' => $schema->string()
                ->description('The UTF-8 text contents to write to the file.')
                ->required(),
        ];
    }
}
