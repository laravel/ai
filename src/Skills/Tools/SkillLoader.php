<?php

namespace Laravel\Ai\Skills\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\SkillMode;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;

class SkillLoader implements Tool
{
    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected SkillRegistry $registry
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'skill_load';
    }

    /**
     * Get the description of the tool.
     */
    public function description(): Stringable|string
    {
        return 'Load a skill into the conversation context.';
    }

    /**
     * Run the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $name = (string) $request->string('skill');

        $skill = $this->registry->load($name, SkillMode::Full);

        if (! $skill) {
            return "Skill '{$name}' not found.";
        }

        $escapedName = htmlspecialchars($skill->name, ENT_QUOTES | ENT_XML1);

        $references = '';
        $files = $skill->referenceFiles();

        if (! empty($files)) {
            $fileList = implode(', ', $files);
            $references = <<<XML

<skill_references>
Available files: $fileList
Use the `skill_read` tool with skill="{$escapedName}" and file="<filename>" to read these.
</skill_references>
XML;
        }

        return <<<XML
<skill name="{$escapedName}">
<instructions>
{$skill->instructions}
</instructions>$references
</skill>
XML;
    }

    /**
     * Get the parameter schema for the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()
                ->description('The name of the skill to load')
                ->required(),
        ];
    }
}
