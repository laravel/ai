<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\textarea;

class ChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:chat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Chat with one of your agents';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $agent = agent(
            instructions: 'You are a helpful assistant.',
        );

        while (true) {
            $prompt = trim((string) textarea('Prompt (or type "quit" to exit)...'));

            if (in_array(strtolower($prompt), ['quit', 'exit', 'q'], true)) {
                return Command::SUCCESS;
            }

            if ($prompt === '') {
                continue;
            }

            $response = spin(
                fn () => $agent->prompt($prompt),
                message: 'Thinking...',
            );

            if ($response instanceof StructuredAgentResponse) {
                $response = json_encode($response->structured, JSON_PRETTY_PRINT);
            }

            note((string) $response.PHP_EOL);
        }
    }
}
