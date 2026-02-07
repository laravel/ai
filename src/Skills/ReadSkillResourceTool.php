<?php

namespace Laravel\Ai\Skills;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ReadSkillResourceTool implements Tool
{
    /**
     * Create a new tool instance.
     */
    public function __construct(protected SkillRegistry $registry) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'read_skill_resource';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Read a resource file from an activated skill. Resources include scripts, references, and assets referenced in the skill instructions.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $skill = $this->registry->findOrFail($request->string('skill'));

        $content = $skill->resource($request->string('path'));

        if ($content === null) {
            return 'Resource not found.';
        }

        return $content;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()->description('The name of the skill.')->required(),
            'path' => $schema->string()->description('The relative path to the resource file, e.g. scripts/lint.sh or references/STYLE_GUIDE.md.')->required(),
        ];
    }
}
