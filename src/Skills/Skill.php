<?php

namespace Laravel\Ai\Skills;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final readonly class Skill
{
    /**
     * Create a new skill instance.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $instructions,
        public string $source = 'local',
        public ?string $basePath = null,
    ) {}

    /**
     * Get the URL-friendly slug for the skill name.
     */
    public function slug(): string
    {
        return Str::slug($this->name);
    }

    /**
     * Get the reference files available in the skill's base directory.
     *
     * @return array<int, string>
     */
    public function referenceFiles(): array
    {
        if (! $this->basePath || ! is_dir($this->basePath)) {
            return [];
        }

        $finder = (new Finder)
            ->files()
            ->followLinks()
            ->in($this->basePath)
            ->name(['*.md', '*.txt', '*.yaml', '*.yml', '*.json'])
            ->notName('SKILL.md');

        return collect($finder)
            ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
            ->values()
            ->all();
    }
}
