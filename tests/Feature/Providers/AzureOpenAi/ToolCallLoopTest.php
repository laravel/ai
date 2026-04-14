<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.openai.azure.com',
        'api_version' => '2024-10-21',
        'deployment' => 'gpt-4o',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'my-resource.openai.azure.com/*' => Http::sequence([
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'azure',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'my-resource.openai.azure.com/*' => Http::sequence([
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'azure',
    );

    $recorded = Http::recorded();

    // ToolUsingAgent has 1 tool + structured output tool = 2 tools
    // maxSteps = round(2 * 1.5) = 3
    // So max 3 requests before stopping (initial + 2 follow-ups)
    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool call follow up preserves deployment name when response model differs', function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'deployment' => 'my-custom-gpt4o-deployment',
    ]]);

    Http::fake([
        'my-resource.openai.azure.com/*' => Http::sequence([
            // First response: Azure returns underlying model name, not deployment name
            Http::response([
                'id' => 'chatcmpl-tool-123',
                'object' => 'chat.completion',
                'model' => 'gpt-4o-2024-08-06',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'azure',
    );

    $recorded = Http::recorded();
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    // The follow-up request must use the deployment name, not the response model
    expect($followUpBody['model'])->toBe('my-custom-gpt4o-deployment');
});

function fakeUniqueAzureToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
