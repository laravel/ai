<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\RealtimeSession;
use Laravel\Ai\Tools\Request as ToolRequest;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiRealtimeSessionResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'sess_test_12345',
        'object' => 'realtime.client_secret',
        'value' => 'ek_secret_abc123',
        'expires_at' => 1790000000,
        'session' => [
            'type' => 'realtime',
            'model' => 'gpt-4o-realtime-preview',
            'modalities' => ['text', 'audio'],
            'instructions' => 'You are a helpful assistant.',
            'audio' => [
                'output' => [
                    'voice' => 'alloy',
                ],
            ],
        ],
    ]);
}

class TestSearchTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search database';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        return 'database result';
    }

    public function schema($factory): array
    {
        return [
            'query' => $factory->string()->description('Search query term'),
        ];
    }
}

test('openai realtime session sends request with model, voice, instructions, and tools', function (): void {
    Http::fake(['*' => fakeOpenAiRealtimeSessionResponse()]);

    $agent = agent(
        instructions: 'You are a support assistant.',
        tools: [new TestSearchTool],
    );

    $session = $agent->realtime(
        provider: 'openai',
        model: 'gpt-4o-realtime-preview',
        voice: 'alloy',
        options: [
            'turn_detection' => ['type' => 'server_vad'],
        ],
    );

    expect($session)->toBeInstanceOf(RealtimeSession::class)
        ->and($session->id())->toBe('sess_test_12345')
        ->and($session->clientSecret())->toBe('ek_secret_abc123')
        ->and($session->expiresAt())->toBe(1790000000)
        ->and($session->model())->toBe('gpt-4o-realtime-preview')
        ->and($session->voice())->toBe('alloy');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $session = $body['session'] ?? [];

        return $request->url() === 'https://api.openai.com/v1/realtime/client_secrets'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $session['type'] === 'realtime'
            && $session['model'] === 'gpt-4o-realtime-preview'
            && $session['audio']['output']['voice'] === 'alloy'
            && $session['instructions'] === 'You are a support assistant.'
            && $session['audio']['input']['turn_detection'] === ['type' => 'server_vad']
            && count($session['tools']) === 1
            && $session['tools'][0]['name'] === 'TestSearchTool'
            && $session['tools'][0]['description'] === 'Search database';
    });
});

test('openai realtime session maps default-female and default-male voices', function (): void {
    Http::fake(['*' => fakeOpenAiRealtimeSessionResponse()]);

    $agent = agent(instructions: 'Voice check');

    $agent->realtime(provider: 'openai', voice: 'default-female');
    Http::assertSent(fn (Request $req) => (json_decode($req->body(), true)['session']['audio']['output']['voice'] ?? null) === 'alloy');

    $agent->realtime(provider: 'openai', voice: 'default-male');
    Http::assertSent(fn (Request $req) => (json_decode($req->body(), true)['session']['audio']['output']['voice'] ?? null) === 'ash');
});
