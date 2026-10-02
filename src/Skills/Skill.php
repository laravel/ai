<?php

namespace Laravel\Ai\Skills;

use Stringable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Skill
{
    /**
     * Create a new skill instance.
     *
     * @param  array<string, Stringable|string>  $files
     */
    public function __construct(
        public readonly string $name,
        public readonly Stringable|string $description,
        public readonly Stringable|string $instructions,
        public readonly array $files = [],
        public readonly ?string $path = null,
    ) {}

    /**
     * Create a skill from a directory containing a SKILL.md file.
     */
    public static function fromDirectory(string $directory): ?self
    {
        $directory = rtrim($directory, '/\\');

        if (! is_file($file = $directory.'/SKILL.md')) {
            return null;
        }

        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', (string) file_get_contents($file), $matches)) {
            return null;
        }

        try {
            $frontmatter = (array) Yaml::parse($matches[1]);
        } catch (ParseException $e) {
            $e->setParsedFile($file);

            throw $e;
        }

        $description = $frontmatter['description'] ?? null;

        if (! is_string($description) || blank($description)) {
            return null;
        }

        $name = $frontmatter['name'] ?? null;

        return new self(
            name: is_scalar($name) && ! blank($name) ? (string) $name : basename($directory),
            description: $description,
            instructions: trim($matches[2]),
            path: $directory,
        );
    }
}
