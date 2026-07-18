<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:tool')]
#[Description('Create a new agent tool')]
class MakeToolCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:tool';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Tool';

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    #[\Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Ai\Tools';
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return $this->resolveStubPath('/stubs/tool.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.'/../../../'.$stub;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    #[\Override]
    protected function getOptions()
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the tool even if the tool already exists'],
        ];
    }
}
