<?php

namespace Laravel\Ai\Skills;

use Stringable;

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

        $frontmatter = $matches[1];

        $description = static::frontmatter($frontmatter, 'description');

        if ($description === null) {
            return null;
        }

        return new self(
            name: static::frontmatter($frontmatter, 'name') ?? basename($directory),
            description: $description,
            instructions: trim($matches[2]),
            path: $directory,
        );
    }

    /**
     * Read a frontmatter value, matched by line rather than parsed as YAML so unquoted colons still load.
     */
    protected static function frontmatter(string $frontmatter, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').':[ \t]*(\S.*)$/m', $frontmatter, $matches)) {
            return null;
        }

        return preg_replace('/\A(["\'])(.*)\1\z/', '$2', trim($matches[1])) ?: null;
    }
}
