<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Tools\Request;
use Throwable;

#[Strict]
class MoveFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Move or rename a file on the filesystem disk.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $from = $request->string('from');
        $to = $request->string('to');

        try {
            $moved = $this->disk()->move($from, $to);
        } catch (Throwable $throwable) {
            return "Unable to move [{$from}] to [{$to}]: {$throwable->getMessage()}";
        }

        return $moved
            ? "Moved [{$from}] to [{$to}]."
            : "Unable to move [{$from}] to [{$to}]. The source file may not exist.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('The source file path, relative to the disk root.')
                ->required(),
            'to' => $schema->string()
                ->description('The destination file path, relative to the disk root.')
                ->required(),
        ];
    }
}
