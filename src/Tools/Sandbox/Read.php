<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class Read extends SandboxTool
{
    /**
     * The number of lines returned when no limit is given.
     */
    protected const DEFAULT_LIMIT = 2000;

    /**
     * The largest file the tool will load.
     */
    protected const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Read a text file in the workspace. Returns up to 2000 lines or 50 KB; pass offset and limit to read further.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $path = $request->string('path')->value();

        $stat = $this->sandbox->stat($path);

        if ($stat === null || ! $stat->isFile) {
            return "File [{$path}] does not exist.";
        }

        if ($stat->size > static::MAX_FILE_SIZE) {
            return "File [{$path}] is too large to read. Use Bash with head, tail, or sed to read part of it.";
        }

        $contents = $this->sandbox->read($path);

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            return "File [{$path}] is binary and cannot be read as text.";
        }

        $lines = explode("\n", $contents);
        $offset = max(1, $request->integer('offset', 1));
        $limit = max(1, $request->integer('limit', static::DEFAULT_LIMIT));

        $selected = [];
        $bytes = 0;

        foreach (array_slice($lines, $offset - 1, $limit) as $line) {
            if (($bytes += strlen($line) + 1) > static::MAX_OUTPUT) {
                if ($selected === []) {
                    $selected[] = mb_strcut($line, 0, static::MAX_OUTPUT).' [line truncated]';
                }

                break;
            }

            $selected[] = $line;
        }

        $last = $offset + count($selected) - 1;

        return $last < count($lines)
            ? implode("\n", $selected)."\n[lines {$offset}-{$last} of ".count($lines).'; pass offset to read more]'
            : implode("\n", $selected);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The file path, relative to the workspace.')->required(),
            'offset' => $schema->integer()->description('The line number to start reading from, starting at 1.'),
            'limit' => $schema->integer()->description('The number of lines to read.'),
        ];
    }
}
