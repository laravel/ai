<?php

namespace Laravel\Ai\Skills;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ActivateSkillTool implements Tool
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
        return 'activate_skill';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Activate a skill by name to get its full instructions. Use when a task matches one of the available skills.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $skill = $this->registry->findOrFail($request->string('name'));

        $output = $skill->content;

        $resources = $skill->resources();

        if (! empty($resources)) {
            $output .= "\n\n<available_resources>\n";
            $output .= implode("\n", $resources);
            $output .= "\n</available_resources>";
        }

        return $output;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The name of the skill to activate.')->required(),
        ];
    }
}
