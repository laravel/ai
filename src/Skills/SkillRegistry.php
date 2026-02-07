<?php

namespace Laravel\Ai\Skills;

use Laravel\Ai\Skills\Exceptions\SkillNotFoundException;

class SkillRegistry
{
    /**
     * The registered skills.
     *
     * @var array<string, Skill>
     */
    protected array $skills = [];

    /**
     * Create a registry from a directory of skills.
     */
    public static function fromDirectory(string $directory): static
    {
        $registry = new static;

        foreach ((new SkillLoader)->loadFromDirectory($directory) as $skill) {
            $registry->register($skill);
        }

        return $registry;
    }

    /**
     * Register a skill.
     */
    public function register(Skill $skill): void
    {
        $this->skills[$skill->name] = $skill;
    }

    /**
     * Get all registered skills.
     *
     * @return array<Skill>
     */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * Find a skill by name.
     */
    public function find(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * Find a skill by name or throw an exception.
     *
     * @throws SkillNotFoundException
     */
    public function findOrFail(string $name): Skill
    {
        $skill = $this->find($name);

        if (! $skill) {
            throw SkillNotFoundException::forName($name);
        }

        return $skill;
    }

    /**
     * Check if a skill is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * Get the count of registered skills.
     */
    public function count(): int
    {
        return count($this->skills);
    }

    /**
     * Generate XML prompt for all registered skills.
     */
    public function toPrompt(): string
    {
        if (empty($this->skills)) {
            return '';
        }

        $xml = "<available_skills>\n";

        foreach ($this->skills as $skill) {
            $xml .= $skill->toXml()."\n";
        }

        $xml .= '</available_skills>';

        return $xml;
    }
}
