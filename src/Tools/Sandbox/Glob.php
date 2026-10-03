<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class Glob extends SandboxTool
{
    /**
     * The maximum number of paths returned.
     */
    protected const MAX_RESULTS = 1000;

    /**
     * The directories never descended into.
     */
    protected const SKIPPED = ['.git', 'node_modules', 'vendor'];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Find files in the workspace whose paths match a glob such as **/*.php. Skips .git, node_modules, and vendor.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $base = $this->sandbox->resolvePath($path = $request->string('path', '.')->value());

        if (! $this->sandbox->stat($base)?->isDirectory) {
            return "Directory [{$path}] does not exist.";
        }

        $regex = '#^'.strtr(preg_quote($request->string('pattern')->value(), '#'), [
            '\*\*/' => '(?:.*/)?',
            '\*\*' => '.*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
        ]).'$#';

        $prune = implode(' -o ', array_map(fn (string $name) => '-name '.escapeshellarg($name), static::SKIPPED));

        $result = $this->sandbox->exec('find '.escapeshellarg($base)." \\( {$prune} \\) -prune -o -type f -print");

        $prefix = rtrim($base, '/').'/';

        $matches = collect(explode("\n", trim($result->stdout)))
            ->filter(fn (string $file) => str_starts_with($file, $prefix) && preg_match($regex, substr($file, strlen($prefix))))
            ->map(fn (string $file) => $this->relative($file))
            ->take(static::MAX_RESULTS)
            ->all();

        if ($matches === []) {
            return 'No files found.';
        }

        sort($matches);

        return count($matches) >= static::MAX_RESULTS
            ? implode("\n", $matches)."\n[results truncated at ".static::MAX_RESULTS.']'
            : implode("\n", $matches);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()->description('The glob to match, such as **/*.php or src/*.ts.')->required(),
            'path' => $schema->string()->description('The directory to search, relative to the workspace.'),
        ];
    }
}
