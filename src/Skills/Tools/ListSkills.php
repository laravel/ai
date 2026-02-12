<?php

namespace Laravel\Ai\Skills\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;

class ListSkills implements Tool
{
    /**
     * The memoized description of the tool.
     */
    private ?string $memoizedDescription = null;

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
        return 'skill_list';
    }

    /**
     * Get the description of the tool.
     */
    public function description(): Stringable|string
    {
        if ($this->memoizedDescription) {
            return $this->memoizedDescription;
        }

        $skills = $this->registry->discover();

        $xml = $skills->map(fn ($skill) => sprintf(
            '  <skill name="%s" description="%s" />',
            htmlspecialchars($skill->name, ENT_QUOTES | ENT_XML1),
            htmlspecialchars($skill->description, ENT_QUOTES | ENT_XML1),
        ))->implode("\n");

        return $this->memoizedDescription = <<<XML
List the available skills that can be loaded.
Available skills:
<available_skills>
$xml
</available_skills>
XML;
    }

    /**
     * Run the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $skills = $this->registry->discover();

        if ($skills->isEmpty()) {
            return 'No skills found.';
        }

        $header = "| Name | Description | Source | Status |\n|---|---|---|---|\n";

        $rows = $skills->map(function ($skill) {
            $status = $this->registry->isLoaded($skill->name) ? 'Loaded' : 'Available';

            return sprintf(
                '| %s | %s | %s | %s |',
                $skill->name,
                $skill->description,
                $skill->source,
                $status
            );
        })->implode("\n");

        return $header.$rows;
    }

    /**
     * Get the parameter schema for the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
