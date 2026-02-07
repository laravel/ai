<?php

namespace Laravel\Ai\Skills\Exceptions;

class SkillNotFoundException extends SkillException
{
    /**
     * Create a new exception instance.
     */
    public static function forName(string $name): self
    {
        return new self("Skill [{$name}] not found.");
    }
}
