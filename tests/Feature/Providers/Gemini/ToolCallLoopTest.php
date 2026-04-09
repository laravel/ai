<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Providers\Gemini\GeminiHelpers;

uses(GeminiHelpers::class);

test('tool calls trigger follow up request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            geminiFakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'gemini',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    $hasModelWithFunctionCall = false;
    $hasFunctionResponse = false;

    foreach ($followUpContents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    $hasModelWithFunctionCall = true;
                }
            }
        }

        if ($content['role'] === 'user') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse'])) {
                    $hasFunctionResponse = true;
                }
            }
        }
    }

    expect($hasModelWithFunctionCall)->toBeTrue('Follow-up request should include model message with functionCall');
    expect($hasFunctionResponse)->toBeTrue('Follow-up request should include user message with functionResponse');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            geminiFakeUniqueToolCallResponse(),
            geminiFakeUniqueToolCallResponse(),
            geminiFakeUniqueToolCallResponse(),
            geminiFakeUniqueToolCallResponse(),
            geminiFakeUniqueToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'gemini',
    );

    $recorded = Http::recorded();

    // ToolUsingAgent has 1 tool, maxSteps = count($tools) * 2 = 2
    // So max 3 requests (initial + 2 follow-ups)
    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('function response includes id for gemini 3', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    $functionResponsePart = null;

    foreach ($followUpContents as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse'])) {
                $functionResponsePart = $part['functionResponse'];
            }
        }
    }

    expect($functionResponsePart)->not->toBeNull('Follow-up should include functionResponse');
    expect($functionResponsePart['name'])->toBe('FixedNumberGenerator');
    expect($functionResponsePart)->toHaveKey('id');
    expect($functionResponsePart)->toHaveKey('response');
    expect($functionResponsePart['response'])->toHaveKey('name');
    expect($functionResponsePart['response'])->toHaveKey('content');
});

test('parallel function calls preserve unique ids', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                            ['functionCall' => ['id' => 'call_2', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate two', provider: 'gemini');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    $functionResponseIds = [];

    foreach ($followUpContents as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse']['id'])) {
                $functionResponseIds[] = $part['functionResponse']['id'];
            }
        }
    }

    expect($functionResponseIds)->toHaveCount(2);
    expect($functionResponseIds)->toContain('call_1');
    expect($functionResponseIds)->toContain('call_2');
});

test('thinking parts are excluded from tool call continuation', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Let me think about this...', 'thought' => true],
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    foreach ($followUpContents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] as $part) {
                expect($part['thought'] ?? false)->toBeFalse('Thinking parts should be excluded from tool call continuation');
            }
        }
    }
});

/**
 * Create a tool call response with unique IDs for use in sequences.
 */
function geminiFakeUniqueToolCallResponse(): PromiseInterface
{
    return Http::response([
        'candidates' => [[
            'content' => [
                'parts' => [[
                    'functionCall' => [
                        'id' => 'call_'.uniqid(),
                        'name' => 'FixedNumberGenerator',
                        'args' => (object) [],
                    ],
                ]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 5,
            'totalTokenCount' => 15,
        ],
        'modelVersion' => 'gemini-3-flash-preview',
    ]);
}
