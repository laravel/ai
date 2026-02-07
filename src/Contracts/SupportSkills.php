<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Skills\Skill;

interface SupportSkills
{
    /**
     * Get the skills available to the agent.
     *
     * Skills may be loaded from any source: local filesystem, S3,
     * database, or any other storage backend.
     *
     * @return array<Skill>
     */
    public function skills(): array;
}
