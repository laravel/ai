<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\RealtimeSession;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'azure-test-key',
        'url' => 'https://test-azure.openai.azure.com',
        'realtime_deployment' => 'my-realtime-deployment',
    ]]);
});

function fakeAzureRealtimeSessionResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'sess_azure_98765',
        'object' => 'realtime.session',
        'model' => 'my-realtime-deployment',
        'modalities' => ['text', 'audio'],
        'instructions' => 'You are an Azure support agent.',
        'voice' => 'alloy',
        'client_secret' => [
            'value' => 'ek_azure_secret_789',
            'expires_at' => 1795000000,
        ],
    ]);
}

test('azure realtime session sends request with api-key and deployment', function (): void {
    Http::fake(['*' => fakeAzureRealtimeSessionResponse()]);

    $agent = agent(instructions: 'You are an Azure support agent.');

    $session = $agent->realtime(
        provider: 'azure',
        voice: 'alloy',
    );

    expect($session)->toBeInstanceOf(RealtimeSession::class)
        ->and($session->id())->toBe('sess_azure_98765')
        ->and($session->clientSecret())->toBe('ek_azure_secret_789')
        ->and($session->expiresAt())->toBe(1795000000)
        ->and($session->model())->toBe('my-realtime-deployment')
        ->and($session->meta->provider)->toBe('azure');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://test-azure.openai.azure.com/openai/v1/realtime/sessions'
            && $request->hasHeader('api-key', 'azure-test-key')
            && $body['model'] === 'my-realtime-deployment'
            && $body['instructions'] === 'You are an Azure support agent.';
    });
});
