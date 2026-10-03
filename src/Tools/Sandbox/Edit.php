<?php

namespace Laravel\Ai\Tools\Sandbox;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class Edit extends SandboxTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Replace exact text in a file in the workspace. The old text must match once unless replace_all is set.';
    }

    /**
     * Run the tool against the sandbox.
     */
    protected function run(Request $request): string
    {
        $path = $request->string('path')->value();
        $old = $request->string('old')->value();

        if (! $this->sandbox->stat($path)?->isFile) {
            return "File [{$path}] does not exist.";
        }

        $contents = $this->sandbox->read($path);

        $count = $old === '' ? 0 : substr_count($contents, $old);

        if ($count === 0) {
            return "The text to replace was not found in [{$path}].";
        }

        if ($count > 1 && ! $request->boolean('replace_all')) {
            return "The text to replace appears {$count} times in [{$path}]. Include more surrounding text or set replace_all.";
        }

        $this->sandbox->write($path, str_replace($old, $request->string('new')->value(), $contents));

        return "Replaced {$count} occurrence(s) in [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The file path, relative to the workspace.')->required(),
            'old' => $schema->string()->description('The exact text to replace.')->required(),
            'new' => $schema->string()->description('The replacement text.')->required(),
            'replace_all' => $schema->boolean()->description('Replace every occurrence instead of exactly one.'),
        ];
    }
}
