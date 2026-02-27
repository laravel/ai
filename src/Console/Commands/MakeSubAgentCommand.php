<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:subagent')]
class MakeSubAgentCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:subagent';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new subagent';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'SubAgent';

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Ai\Agents';
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub()
    {
        return $this->resolveStubPath('/stubs/subagent.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.'/../../../'.$stub;
    }

    /**
     * Get the console command arguments.
     */
    protected function getOptions()
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the subagent even if the subagent already exists'],
        ];
    }
}
