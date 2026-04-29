<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\FileAttachmentToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
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
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool call follow up request preserves the originally requested model alias', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponseWithModel('mistral-medium-2026-04-28'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'mistral',
        model: 'mistral-medium-latest',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('mistral-medium-latest')
        ->and($followUpBody['model'])->toBe('mistral-medium-latest');
});

test('tool call follow up request reuses mapped attachment contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'mistral-attachment-').'.png';
    copy(__DIR__.'/../../../Fixtures/Images/red.png', $path);
    $expectedUrl = 'data:image/png;base64,'.base64_encode(file_get_contents($path));

    try {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FileMutatingTool'),
                $this->fakeTextResponse('done'),
            ]),
        ]);

        (new FileAttachmentToolAgent($path))->prompt(
            'Inspect this image, then use the tool.',
            attachments: [new LocalImage($path, 'image/png')],
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(2)
            ->and(file_get_contents($path))->toBe('mutated attachment contents');

        $initialUser = collect(json_decode($recorded[0][0]->body(), true)['messages'])->firstWhere('role', 'user');
        $followUpUser = collect(json_decode($recorded[1][0]->body(), true)['messages'])->firstWhere('role', 'user');

        expect(collect($initialUser['content'])->firstWhere('type', 'image_url')['image_url']['url'])->toBe($expectedUrl)
            ->and(collect($followUpUser['content'])->firstWhere('type', 'image_url')['image_url']['url'])->toBe($expectedUrl);
    } finally {
        @unlink($path);
    }
});

test('follow up request includes original messages', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $userMsg = collect($followUpBody['messages'])->firstWhere('role', 'user');

    expect($userMsg)->not->toBeNull();
});
