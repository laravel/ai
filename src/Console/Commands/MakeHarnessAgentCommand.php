<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:harness-agent')]
class MakeHarnessAgentCommand extends GeneratorCommand
{
    protected $name = 'make:harness-agent';

    protected $description = 'Create a new harness agent';

    protected $type = 'Harness agent';

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\\Ai\\Harnesses';
    }

    protected function getStub()
    {
        return file_exists($path = $this->laravel->basePath('stubs/harness-agent.stub'))
            ? $path : __DIR__.'/../../../stubs/harness-agent.stub';
    }
}
