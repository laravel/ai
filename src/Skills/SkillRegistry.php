<?php

namespace Laravel\Ai\Skills;

use Illuminate\Support\Collection;

class SkillRegistry
{
    /**
     * The loaded skills.
     *
     * @var array<string, Skill>
     */
    protected array $skills = [];

    /**
     * The modes for the loaded skills.
     *
     * @var array<string, SkillMode>
     */
    protected array $skillModes = [];

    public function __construct(
        protected SkillDiscovery $discovery
    ) {}

    /**
     * Load a skill by name with an optional discovery mode.
     */
    public function load(string $name, SkillMode|string|null $mode = null): ?Skill
    {
        $skill = $this->discovery->resolve($name);

        if ($skill) {
            $this->skills[$skill->name] = $skill;

            if ($mode) {
                $this->skillModes[$skill->name] = $mode instanceof SkillMode ? $mode : SkillMode::fromValue($mode);
            }
        }

        return $skill;
    }

    /**
     * Determine if a skill is currently loaded.
     */
    public function isLoaded(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * Get a loaded skill by name.
     */
    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * @return array<string, Skill>
     */
    public function getLoaded(): array
    {
        return $this->skills;
    }

    /**
     * Discover all available skills.
     */
    public function discover(): Collection
    {
        return $this->discovery->discover();
    }

    /**
     * Get the aggregated instructions for all loaded skills.
     */
    public function instructions(SkillMode|string|null $mode = null): string
    {
        $globalMode = $mode instanceof SkillMode
            ? $mode
            : SkillMode::fromValue($mode ?? config('ai.skills.default_mode', 'full'));

        return collect($this->skills)
            ->map(function (Skill $skill) use ($globalMode) {
                $skillMode = $this->skillModes[$skill->name] ?? $globalMode;

                if ($skillMode === SkillMode::Full) {
                    return sprintf(
                        '<skill name="%s">%s%s%s</skill>',
                        htmlspecialchars($skill->name, ENT_QUOTES | ENT_XML1),
                        PHP_EOL,
                        $skill->instructions,
                        PHP_EOL
                    );
                }

                if ($skillMode === SkillMode::Lite) {
                    return sprintf(
                        '<skill name="%s" description="%s" />',
                        htmlspecialchars($skill->name, ENT_QUOTES | ENT_XML1),
                        htmlspecialchars($skill->description, ENT_QUOTES | ENT_XML1)
                    );
                }

                return '';
            })
            ->filter()
            ->implode(PHP_EOL);
    }
}
