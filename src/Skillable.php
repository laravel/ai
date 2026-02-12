<?php

namespace Laravel\Ai;

use Laravel\Ai\Skills\SkillMode;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\ListSkills;
use Laravel\Ai\Skills\Tools\SkillLoader;
use Laravel\Ai\Skills\Tools\SkillReferenceReader;

trait Skillable
{
    /**
     * The skill registry instance.
     */
    protected ?SkillRegistry $skillRegistry = null;

    /**
     * Get the skills assigned to the agent.
     */
    public function skills(): iterable
    {
        return [];
    }

    /**
     * Get the instructions provided by the agent's skills.
     */
    public function skillInstructions(SkillMode|string|null $mode = null): string
    {
        $this->bootSkillsIfNeeded();

        return $this->skillRegistry->instructions($mode);
    }

    /**
     * Get the meta-tools for skill management.
     *
     * @return array<int, object>
     */
    public function skillTools(): array
    {
        $this->bootSkillsIfNeeded();

        return [
            app(ListSkills::class),
            app(SkillLoader::class),
            app(SkillReferenceReader::class),
        ];
    }

    /**
     * Boot the skill registry if it has not already been initialized.
     */
    protected function bootSkillsIfNeeded(): void
    {
        if ($this->skillRegistry !== null) {
            return;
        }

        $this->skillRegistry = app(SkillRegistry::class);

        foreach ($this->skills() as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $mode = is_int($key) ? null : $value;

            $this->skillRegistry->load($name, $mode);
        }
    }
}
