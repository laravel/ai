<?php

namespace Laravel\Ai\Skills;

use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;

class SkillDiscovery
{
    /**
     * Create a new skill discovery instance.
     *
     * @param  array<int, string>  $paths
     */
    public function __construct(
        protected array $paths
    ) {}

    /**
     * Discover all available skills from configured paths.
     */
    public function discover(): Collection
    {
        return $this->scanLocal();
    }

    /**
     * Resolve a single skill by its name.
     */
    public function resolve(string $name): ?Skill
    {
        return $this->discover()->first(fn (Skill $skill) => $skill->name === $name);
    }

    /**
     * Scan the local filesystem for skill definitions.
     */
    protected function scanLocal(): Collection
    {
        $skills = collect();

        if (empty($this->paths)) {
            return $skills;
        }

        $existingPaths = array_filter($this->paths, 'is_dir');

        if (empty($existingPaths)) {
            return $skills;
        }

        $finder = new Finder;
        $finder->files()
            ->followLinks()
            ->in($existingPaths)
            ->name('SKILL.md')
            ->depth('== 1');

        foreach ($finder as $file) {
            $skill = SkillParser::parse(
                $file->getContents(),
                'local',
                $file->getPath()
            );

            if ($skill) {
                $skills->push($skill);
            }
        }

        return $skills;
    }
}
