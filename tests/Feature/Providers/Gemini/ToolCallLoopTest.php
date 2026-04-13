<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

test('tool calls trigger follow up request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
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

    expect($hasModelWithFunctionCall)->toBeTrue('Follow-up request should include model message with functionCall')
        ->and($hasFunctionResponse)->toBeTrue('Follow-up request should include user message with functionResponse');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
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

    expect($functionResponsePart)->not->toBeNull('Follow-up should include functionResponse')
        ->and($functionResponsePart['name'])->toBe('FixedNumberGenerator')
        ->and($functionResponsePart)->toHaveKeys(['id', 'response'])
        ->and($functionResponsePart['response'])->toHaveKeys(['name', 'content']);
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

    expect($functionResponseIds)->toHaveCount(2)
        ->toContain('call_1')
        ->toContain('call_2');
});

test('non-streaming continuation produces matching ids when gemini omits id', function () {
    // Reproduces laravel/ai#388 on the non-streaming path: when Gemini omits
    // an id on functionCall, the continuation request must not send an
    // unmatched fabricated id on functionResponse.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    [$functionCallIds, $functionResponseIds] = $this->extractToolCallIds(
        Http::recorded()[1][0]->data()['contents'],
    );

    expect($functionCallIds)->toHaveCount(1)
        ->and($functionResponseIds)->toHaveCount(1)
        ->and($functionCallIds[0])->toBe(
            $functionResponseIds[0],
            'functionCall.id and functionResponse.id must match — mismatch causes Gemini 400',
        );
});

test('non-streaming continuation preserves gemini supplied id verbatim', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'gemini_call_zzz'),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    [$functionCallIds, $functionResponseIds] = $this->extractToolCallIds(
        Http::recorded()[1][0]->data()['contents'],
    );

    expect($functionCallIds)
        ->toBe(['gemini_call_zzz'], 'functionCall id must be preserved verbatim per Gemini docs')
        ->and($functionResponseIds)
        ->toBe(['gemini_call_zzz'], 'functionResponse id must echo the functionCall id exactly');
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
