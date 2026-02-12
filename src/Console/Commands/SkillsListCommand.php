<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillDiscovery;

class SkillsListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'skill:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available AI skills';

    /**
     * Execute the console command.
     */
    public function handle(SkillDiscovery $discovery): int
    {
        $skills = $discovery->discover();

        if ($skills->isEmpty()) {
            $this->info('No skills found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Description', 'Source'],
            $skills->map(fn (Skill $s) => [$s->name, $s->description, $s->source])
        );

        return self::SUCCESS;
    }
}
