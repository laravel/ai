<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('prompt reads reasoning items off the response', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'resp_azure_123',
        'status' => 'completed',
        'model' => 'o4-mini',
        'output' => [
            openAiReasoningItem('rs_1', 'Let me ', 'think...'),
            [
                'type' => 'message',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'Hello']],
            ],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'azure');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});
