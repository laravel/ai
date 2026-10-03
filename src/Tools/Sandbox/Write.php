<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class Write extends SandboxTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Create or overwrite a file in the workspace, creating missing directories.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $path = $request->string('path')->value();

        $this->sandbox->write($path, $contents = $request->string('contents')->value());

        return 'Wrote '.strlen($contents)." bytes to [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The file path, relative to the workspace.')->required(),
            'contents' => $schema->string()->description('The full contents of the file.')->required(),
        ];
    }
}
