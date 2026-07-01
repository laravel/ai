<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Agent;
use Symfony\Component\Console\Output\OutputInterface;

class RunAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'agent:run
        {agent : The agent class to run}
        {prompt? : The prompt to send to the agent}
        {--provider= : The provider the agent should use}
        {--model= : The model the agent should use}
        {--timeout= : The request timeout in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Run an AI agent and write its response to the output';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $agent = $this->argument('agent');

        if (! is_a($agent, Agent::class, true)) {
            $this->components->error("The [{$agent}] class is not a valid agent.");

            return self::FAILURE;
        }

        $response = $agent::make()->prompt(
            $this->argument('prompt') ?? '',
            provider: $this->option('provider'),
            model: $this->option('model'),
            timeout: is_null($this->option('timeout')) ? null : (int) $this->option('timeout'),
        );

        $this->output->write($response->text, true, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
