<?php

use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\BedrockCachingAgent;

beforeEach(function () {
    $this->captured = null;
    $this->run = function (object $agent) {
        $this->gatewayWithClient($this->capturingBedrockClient($this->captured))->generateText(
            $this->bedrockProvider(),
            'anthropic.claude-haiku-4-5-20251001-v1:0',
            'You are a helpful assistant.',
            [],
            [],
            null,
            TextGenerationOptions::forAgent($agent),
        );
    };
});

test('cachePoint marker is appended to system blocks', function () {
    ($this->run)(new BedrockCachingAgent);

    expect($this->captured['system'])->toHaveCount(2)
        ->and($this->captured['system'][1])->toMatchArray(['cachePoint' => ['type' => 'default']]);
});

test('cache directive does not leak into converse parameters', function () {
    ($this->run)(new BedrockCachingAgent);

    expect($this->captured)->not->toHaveKey('cache');
});

test('system stays a single block without a cache directive', function () {
    ($this->run)(new AssistantAgent);

    expect($this->captured['system'])->toHaveCount(1);
});
