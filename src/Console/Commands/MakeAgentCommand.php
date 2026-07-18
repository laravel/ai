<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(name: 'make:agent')]
#[Description('Create a new agent')]
class MakeAgentCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:agent';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Agent';

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    #[\Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Ai\Agents';
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if ($this->option('structured')) {
            return $this->resolveStubPath('/stubs/structured-agent.stub');
        }

        return $this->resolveStubPath('/stubs/agent.stub');
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
            ['force', 'f', InputOption::VALUE_NONE, 'Create the agent even if the agent already exists'],
            ['structured', 's', InputOption::VALUE_NONE, 'Generate an agent that returns structured output'],
        ];
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     *
     * @return void
     */
    #[\Override]
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output)
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $input->setOption('structured', confirm(
            label: 'Will your agent generate structured output?',
            default: false,
        ));
    }
}
