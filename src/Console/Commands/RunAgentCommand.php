<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Document;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RunAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:run
        {agent : The agent class to run}
        {prompt? : The prompt to send to the agent}
        {--provider= : The provider the agent should use}
        {--model= : The model the agent should use}
        {--timeout= : The request timeout in seconds}
        {--attachment=* : A local document path to attach}
        {--storage-attachment=* : A document path on a filesystem disk to attach}
        {--disk= : The filesystem disk containing storage attachments}';

    /**
     * The console command description.
     *
     * @var string
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

        try {
            $response = $this->laravel->make($agent)->prompt(
                $this->argument('prompt') ?? '',
                attachments: $this->attachments(),
                provider: $this->option('provider'),
                model: $this->option('model'),
                timeout: is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : null,
            );
        } catch (Throwable $e) {
            report($e);

            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->output->write($response->text, true, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function attachments(): array
    {
        return [
            ...array_map(Document::fromPath(...), $this->option('attachment')),
            ...array_map(
                fn (string $path) => Document::fromStorage($path, $this->option('disk')),
                $this->option('storage-attachment'),
            ),
        ];
    }
}
