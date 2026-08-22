<?php

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Realtime;
use Laravel\Ai\Responses\RealtimeSession;
use Laravel\Ai\Tools\Request;

use function Laravel\Ai\agent;

class DummyRealtimeTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'A dummy tool for search';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'search result';
    }

    public function schema($factory): array
    {
        return [
            'query' => $factory->string()->description('The search query'),
        ];
    }
}

test('agent can create realtime session and alias clientCredentials', function (): void {
    Realtime::fake();

    $agent = agent(
        instructions: 'You are a customer service assistant',
        tools: [new DummyRealtimeTool],
    );

    $session1 = $agent->realtime(
        voice: 'alloy',
        options: ['temperature' => 0.6],
    );

    expect($session1)->toBeInstanceOf(RealtimeSession::class);

    Realtime::assertSessionCreated(function (RealtimePrompt $prompt): bool {
        return $prompt->contains('You are a customer service assistant')
            && $prompt->voice === 'alloy'
            && $prompt->options['temperature'] === 0.6
            && $prompt->hasTool('DummyRealtimeTool');
    });

    $session2 = $agent->clientCredentials(
        voice: 'echo',
    );

    expect($session2)->toBeInstanceOf(RealtimeSession::class);

    Realtime::assertSessionCreated(function (RealtimePrompt $prompt): bool {
        return $prompt->voice === 'echo';
    });
});
