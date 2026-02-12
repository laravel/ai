<?php

namespace Laravel\Ai\Skills;

enum SkillMode: string
{
    case None = 'none';
    case Lite = 'lite';
    case Full = 'full';

    /**
     * Resolve a skill mode from a string or enum instance.
     */
    public static function fromValue(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::from(strtolower($value));
    }
}
