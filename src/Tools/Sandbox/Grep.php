<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class Grep extends SandboxTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search file contents in the workspace for a regular expression. Returns matching lines as path:line:text.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $path = escapeshellarg($this->sandbox->resolvePath($request->string('path', '.')->value()));
        $pattern = escapeshellarg($request->string('pattern')->value());
        $glob = $request->filled('glob') ? escapeshellarg($request->string('glob')->value()) : null;
        $case = $request->boolean('ignore_case') ? '-i' : '';

        $result = $this->sandbox->exec(sprintf(
            'if command -v rg >/dev/null 2>&1; then rg -n --no-heading --color=never %s %s -e %s %s; else grep -rnE %s %s -e %s %s; fi',
            $case, $glob ? "--glob {$glob}" : '', $pattern, $path,
            $case, $glob ? "--include={$glob}" : '', $pattern, $path,
        ));

        return match (true) {
            $result->exitCode === 1 => 'No matches found.',
            ! $result->successful() => trim($result->stderr) ?: "Search failed with exit code {$result->exitCode}.",
            default => $this->truncate($this->relative(rtrim($result->stdout))),
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()->description('The regular expression to search for.')->required(),
            'path' => $schema->string()->description('The file or directory to search, relative to the workspace.'),
            'glob' => $schema->string()->description('Only search files matching this glob, such as *.php.'),
            'ignore_case' => $schema->boolean()->description('Match case-insensitively.'),
        ];
    }
}
