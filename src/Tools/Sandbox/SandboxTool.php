<?php

namespace Laravel\Ai\Tools\Sandbox;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Laravel\Ai\Sandboxes\Sandbox;
use Laravel\Ai\Tools\Request;

abstract class SandboxTool implements Tool
{
    /**
     * The maximum number of bytes of output returned to the model.
     */
    protected const MAX_OUTPUT = 50 * 1024;

    public function __construct(protected Sandbox $sandbox) {}

    /**
     * Get the default tools for working in the given sandbox.
     *
     * @return array<int, SandboxTool>
     */
    public static function defaults(Sandbox $sandbox, bool $approveCommands = true): array
    {
        $bash = new Bash($sandbox);

        return [
            $approveCommands ? $bash : $bash->withoutApproval(),
            new Read($sandbox),
            new Write($sandbox),
            new Edit($sandbox),
            new Grep($sandbox),
            new Glob($sandbox),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        try {
            return $this->run($request);
        } catch (SandboxPathException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Run the tool against the sandbox.
     */
    abstract protected function run(Request $request): string;

    /**
     * Cut the given output down to the maximum the model is sent.
     */
    protected function truncate(string $output): string
    {
        return strlen($output) > static::MAX_OUTPUT
            ? mb_strcut($output, 0, static::MAX_OUTPUT).PHP_EOL.'[output truncated]'
            : $output;
    }

    /**
     * Strip the sandbox's working directory from the given output's paths.
     */
    protected function relative(string $output): string
    {
        return str_replace(rtrim($this->sandbox->cwd(), '/').'/', '', $output);
    }
}
