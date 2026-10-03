<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

class Bash extends SandboxTool implements Approvable
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Run a shell command in the workspace and return its output and exit code.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $result = $this->sandbox->exec(
            $request->string('command'),
            $request->filled('timeout') ? max(1, $request->integer('timeout')) : null,
        );

        return $this->truncate(implode(PHP_EOL, array_filter([
            rtrim($result->stdout),
            $result->stderr !== '' ? '[stderr]'.PHP_EOL.rtrim($result->stderr) : null,
            $result->timedOut ? '[timed out]' : null,
            "[exit code {$result->exitCode}]",
        ], fn (?string $part) => $part !== null && $part !== '')));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('The shell command to run.')->required(),
            'timeout' => $schema->integer()->description('Seconds to wait before stopping the command.'),
        ];
    }
}
