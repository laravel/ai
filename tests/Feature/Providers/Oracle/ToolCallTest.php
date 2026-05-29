<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(fn () => $this->configureOracle());

function oracleGenericToolCallPayload(): array
{
    return [
        'modelId' => 'meta.llama-3.3-70b-instruct',
        'chatResponse' => [
            'apiFormat' => 'GENERIC',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'ASSISTANT',
                    'content' => [],
                    'toolCalls' => [[
                        'id' => 'call_1',
                        'type' => 'FUNCTION',
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ]],
                ],
                'finishReason' => 'tool_calls',
            ]],
            'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
        ],
    ];
}

function oracleGenericTextPayload(string $text): array
{
    return [
        'modelId' => 'meta.llama-3.3-70b-instruct',
        'chatResponse' => [
            'apiFormat' => 'GENERIC',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'ASSISTANT', 'content' => [['type' => 'TEXT', 'text' => $text]]],
                'finishReason' => 'stop',
            ]],
            'usage' => ['promptTokens' => 20, 'completionTokens' => 10],
        ],
    ];
}

function oracleCohereToolCallPayload(): array
{
    return [
        'modelId' => 'cohere.command-a-03-2025',
        'chatResponse' => [
            'apiFormat' => 'COHERE',
            'text' => '',
            'finishReason' => 'COMPLETE',
            'toolCalls' => [['name' => 'FixedNumberGenerator', 'parameters' => (object) []]],
            'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
        ],
    ];
}

function oracleCohereTextPayload(string $text): array
{
    return [
        'modelId' => 'cohere.command-a-03-2025',
        'chatResponse' => [
            'apiFormat' => 'COHERE',
            'text' => $text,
            'finishReason' => 'COMPLETE',
            'usage' => ['promptTokens' => 20, 'completionTokens' => 10],
        ],
    ];
}

test('generic tool calls are executed and fed back as TOOL messages', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::sequence()
            ->push(oracleGenericToolCallPayload())
            ->push(oracleGenericTextPayload('The number is 72019')),
    ]);

    $response = (new ProviderOptionsWithToolsAgent)->prompt(
        'Generate a number',
        provider: 'oracle',
        model: 'meta.llama-3.3-70b-instruct',
    );

    expect((string) $response)->toBe('The number is 72019')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('72019');

    Http::assertSentCount(2);

    $requests = Http::recorded();
    $secondBody = $requests[1][0]->data();
    $roles = array_column($secondBody['chatRequest']['messages'], 'role');

    expect($roles)->toContain('ASSISTANT')->toContain('TOOL');
});

test('cohere tool calls are executed and fed back as toolResults', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::sequence()
            ->push(oracleCohereToolCallPayload())
            ->push(oracleCohereTextPayload('The number is 72019')),
    ]);

    $response = (new ProviderOptionsWithToolsAgent)->prompt(
        'Generate a number',
        provider: 'oracle',
        model: 'cohere.command-a-03-2025',
    );

    expect((string) $response)->toBe('The number is 72019')
        ->and($response->toolCalls)->toHaveCount(1);

    $requests = Http::recorded();
    $secondBody = $requests[1][0]->data();

    expect($secondBody['chatRequest']['toolResults'][0]['call']['name'])->toBe('FixedNumberGenerator');
});
