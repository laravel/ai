<?php

namespace Laravel\Ai\Skills\Exceptions;

class SkillParseException extends SkillException
{
    /**
     * Create a new exception for missing SKILL.md.
     */
    public static function missingSkillFile(string $path): self
    {
        return new self("SKILL.md not found in [{$path}].");
    }

    /**
     * Create a new exception for invalid frontmatter.
     */
    public static function invalidFrontmatter(string $message): self
    {
        return new self("Invalid SKILL.md frontmatter: {$message}");
    }

    /**
     * Create a new exception for missing required field.
     */
    public static function missingRequiredField(string $field): self
    {
        return new self("Missing required field in SKILL.md frontmatter: {$field}");
    }
}
