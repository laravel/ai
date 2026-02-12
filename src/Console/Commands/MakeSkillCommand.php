<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeSkillCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:skill {name : The name of the skill} {--force : Overwrite the skill if it already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new AI skill';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = Str::kebab($this->argument('name'));
        $directory = resource_path("skills/{$name}");
        $path = "{$directory}/SKILL.md";

        if (File::exists($path) && ! $this->option('force')) {
            $this->error("Skill [{$name}] already exists!");

            return 1;
        }

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $stub = File::get(__DIR__.'/../../../stubs/skill.stub');

        $content = str_replace(
            ['{{ name }}', '{{ slug }}'],
            [Str::headline($name), $name],
            $stub
        );

        File::put($path, $content);

        $this->info("Skill [{$name}] created successfully.");

        return 0;
    }
}
