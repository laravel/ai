<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillLoader;

trait LoadsSkills
{
    /**
     * The resolved skills (memoized per-instance).
     *
     * @var array<Skill>|null
     */
    protected ?array $resolvedSkills = null;

    /**
     * Get the skills available to the agent.
     *
     * By default, loads skills from `resource_path('ai/skills')` with
     * automatic caching. Override this method to load from any source
     * (database, S3, API, etc.).
     *
     * @return array<Skill>
     */
    public function skills(): array
    {
        if ($this->resolvedSkills !== null) {
            return $this->resolvedSkills;
        }

        $paths = $this->skillPaths();

        if (empty($paths)) {
            return $this->resolvedSkills = [];
        }

        $cacheKey = 'laravel_ai_skills:'.md5(static::class.'|'.implode('|', $paths));

        try {
            return $this->resolvedSkills = Cache::remember(
                $cacheKey,
                $this->skillCacheTtl(),
                fn () => $this->loadSkillsFromPaths($paths),
            );
        } catch (\Throwable) {
            return $this->resolvedSkills = $this->loadSkillsFromPaths($paths);
        }
    }

    /**
     * Get the filesystem paths to load skills from.
     *
     * Defaults to `resource_path('ai/skills')`. Override to customize.
     *
     * @return array<string>
     */
    protected function skillPaths(): array
    {
        return [
            resource_path('ai/skills'),
        ];
    }

    /**
     * Get the cache TTL for skills in seconds.
     */
    protected function skillCacheTtl(): int
    {
        return 3600;
    }

    /**
     * Load skills from the given directory paths.
     *
     * @param  array<string>  $paths
     * @return array<Skill>
     */
    protected function loadSkillsFromPaths(array $paths): array
    {
        $loader = new SkillLoader;
        $skills = [];

        foreach ($paths as $path) {
            foreach ($loader->loadFromDirectory($path) as $skill) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Clear the resolved skills cache (both in-memory and persistent).
     */
    public function clearSkillCache(): void
    {
        $this->resolvedSkills = null;

        $paths = $this->skillPaths();

        if (! empty($paths)) {
            try {
                Cache::forget('laravel_ai_skills:'.md5(static::class.'|'.implode('|', $paths)));
            } catch (\Throwable) {
                //
            }
        }
    }
}
