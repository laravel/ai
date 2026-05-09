<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

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

test('tool declaring a name() method routes the function call back to itself', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeToolCallResponse('aliased_tool', 'call_named_1'),
            $this->fakeTextResponse('done'),
        ]),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

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

    expect($functionResponsePart)->not->toBeNull('Follow-up should include a functionResponse for the declared tool name')
        ->and($functionResponsePart['name'])->toBe('aliased_tool');
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

                if (isset($part['text'])) {
                    expect($part['text'])->not->toContain(
                        'Let me think about this...',
                        'Thinking text content must not leak into the assistant turn as a non-flagged text part'
                    );
                }
            }
        }
    }
});

test('functionCall parts on continuation only carry name and args', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    foreach ($followUpContents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    expect(array_keys($part['functionCall']))
                        ->each->toBeIn(['name', 'args'], 'functionCall parts must not echo extraneous fields (e.g. id) back to Gemini');
                }
            }
        }
    }
});
