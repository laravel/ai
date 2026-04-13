<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;

test('text generation options can be created from agent attributes', function () {
    $options = TextGenerationOptions::forAgent(new AttributeAgent);

    expect($options->maxSteps)->toBe(10)
        ->and($options->maxTokens)->toBe(4096)
        ->and($options->temperature)->toBe(0.7);
});

test('text generation options are null when agent has no attributes', function () {
    $options = TextGenerationOptions::forAgent(new AssistantAgent);

    expect($options->maxSteps)->toBeNull()
        ->and($options->maxTokens)->toBeNull()
        ->and($options->temperature)->toBeNull();
});

test('provider attribute is used when prompting', function () {
    AttributeAgent::fake();

    (new AttributeAgent)->prompt('Hello');

    AttributeAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->provider->name() === Lab::Anthropic->value;
    });
});
