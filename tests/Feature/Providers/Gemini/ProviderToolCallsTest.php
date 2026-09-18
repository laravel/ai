<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Tests\Fixtures\Agents\AssistantAgent;

test('code execution parts land on the step', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['executableCode' => ['language' => 'PYTHON', 'code' => 'print(1)']],
                    ['codeExecutionResult' => ['outcome' => 'OUTCOME_OK', 'output' => '1']],
                    ['text' => 'The answer is 1.'],
                ]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Run it', provider: 'gemini');

    expect(array_map(fn (ProviderToolCall $call): array => $call->data, $response->steps[0]->providerToolCalls))->toBe([
        ['executableCode' => ['language' => 'PYTHON', 'code' => 'print(1)']],
        ['codeExecutionResult' => ['outcome' => 'OUTCOME_OK', 'output' => '1']],
    ])->and($response->steps[0]->providerToolCalls[0]->type)->toBe('code_execution');
});
