<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\textarea;

#[Description('Chat with one of your agents')]
#[Signature('agent:chat')]
class ChatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $agent = agent(
            instructions: 'You are a helpful assistant.',
        );

        while (true) {
            $prompt = textarea('Prompt...');

            $response = spin(
                fn (): AgentResponse => $agent->prompt($prompt),
                message: 'Thinking...',
            );

            if ($response instanceof StructuredAgentResponse) {
                $response = json_encode($response->structured, JSON_PRETTY_PRINT);
            }

            note($response.PHP_EOL);
        }

        return Command::SUCCESS;
    }
}
