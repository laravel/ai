<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('follow-up tool result request uses the originally requested model alias, not the snapshot returned by the api', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            $this->fakeOpenAiToolCallResponseWithSnapshot('gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14'),
            $this->fakeOpenAiToolCallResponseWithSnapshot('gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14'),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true, maxStepsOverride: 5))->prompt(
        'Generate a random number',
        provider: 'openai',
        model: 'gpt-4.1-mini',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $firstFollowUpBody = json_decode($recorded[1][0]->body(), true);
    $secondFollowUpBody = json_decode($recorded[2][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('gpt-4.1-mini');
    expect($firstFollowUpBody['model'])->toBe('gpt-4.1-mini');

    expect($secondFollowUpBody['model'])->toBe('gpt-4.1-mini');
});
