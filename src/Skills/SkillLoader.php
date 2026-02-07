<?php

namespace Laravel\Ai\Skills;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Skills\Exceptions\SkillParseException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SkillLoader
{
    /**
     * Load a skill from a directory.
     */
    public function load(string $skillPath): Skill
    {
        $skillMdPath = $this->findSkillMd($skillPath);

        if (! $skillMdPath) {
            throw SkillParseException::missingSkillFile($skillPath);
        }

        $content = File::get($skillMdPath);
        [$frontmatter, $body] = $this->parseFrontmatter($content);

        $this->validateRequiredFields($frontmatter);

        return new Skill(
            name: trim($frontmatter['name']),
            description: trim($frontmatter['description']),
            content: $body,
            path: $skillPath,
            license: $frontmatter['license'] ?? null,
            compatibility: $frontmatter['compatibility'] ?? null,
            allowedTools: $frontmatter['allowed-tools'] ?? null,
            metadata: $frontmatter['metadata'] ?? [],
        );
    }

    /**
     * Load all skills from a directory.
     *
     * @return array<Skill>
     */
    public function loadFromDirectory(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $skills = [];

        foreach (File::directories($directory) as $skillPath) {
            try {
                $skills[] = $this->load($skillPath);
            } catch (SkillParseException) {
                // Skip directories that aren't valid skills
                continue;
            }
        }

        return $skills;
    }

    /**
     * Find the SKILL.md file in a skill directory.
     */
    protected function findSkillMd(string $skillPath): ?string
    {
        foreach (['SKILL.md', 'skill.md'] as $filename) {
            $path = $skillPath.'/'.$filename;
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Parse YAML frontmatter from SKILL.md content.
     *
     * @return array{array<string, mixed>, string}
     */
    protected function parseFrontmatter(string $content): array
    {
        if (! str_starts_with($content, '---')) {
            throw SkillParseException::invalidFrontmatter('SKILL.md must start with YAML frontmatter (---)');
        }

        $parts = explode('---', $content, 3);

        if (count($parts) < 3) {
            throw SkillParseException::invalidFrontmatter('SKILL.md frontmatter not properly closed with ---');
        }

        try {
            $frontmatter = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            throw SkillParseException::invalidFrontmatter($e->getMessage());
        }

        if (! is_array($frontmatter)) {
            throw SkillParseException::invalidFrontmatter('Frontmatter must be a YAML mapping');
        }

        $body = trim($parts[2]);

        return [$frontmatter, $body];
    }

    /**
     * Validate that required fields are present.
     *
     * @param  array<string, mixed>  $frontmatter
     */
    protected function validateRequiredFields(array $frontmatter): void
    {
        if (! isset($frontmatter['name']) || ! is_string($frontmatter['name']) || trim($frontmatter['name']) === '') {
            throw SkillParseException::missingRequiredField('name');
        }

        if (! isset($frontmatter['description']) || ! is_string($frontmatter['description']) || trim($frontmatter['description']) === '') {
            throw SkillParseException::missingRequiredField('description');
        }
    }
}
