<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;

test('text generation options can extract provider options for openai', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $providerOptions = $options->providerOptions(Lab::OpenAI);

    expect($providerOptions)->not->toBeNull()
        ->toBeArray()
        ->toEqual(['reasoning' => [
            'effort' => 'high',
        ], 'frequency_penalty' => 0.5, 'presence_penalty' => 0.3]);
});

test('text generation options can extract provider options for anthropic', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $providerOptions = $options->providerOptions(Lab::Anthropic);

    expect($providerOptions)->not->toBeNull()
        ->toEqual(['thinking' => [
            'type' => 'enabled',
            'budget_tokens' => 10000,
        ]]);
});

test('text generation options accept string provider', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $providerOptions = $options->providerOptions('openai');

    expect($providerOptions)->not->toBeNull()
        ->toEqual(['reasoning' => [
            'effort' => 'high',
        ], 'frequency_penalty' => 0.5, 'presence_penalty' => 0.3]);
});

test('text generation options return empty array for unknown provider', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $providerOptions = $options->providerOptions(Lab::DeepSeek);

    expect($providerOptions)->toBeEmpty();
});

test('text generation options have null provider options when agent does not implement interface', function () {
    $options = TextGenerationOptions::forAgent(new AssistantAgent);

    expect($options->providerOptions(Lab::OpenAI))->toBeNull();
});

test('provider options are passed through when prompting', function () {
    ProviderOptionsAgent::fake();

    (new ProviderOptionsAgent)->prompt('Hello');

    ProviderOptionsAgent::assertPrompted(function (AgentPrompt $prompt) {
        $options = TextGenerationOptions::forAgent($prompt->agent);

        return $options->providerOptions(Lab::OpenAI) === [
            'reasoning' => [
                'effort' => 'high',
            ],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ];
    });
});

test('provider options default to null when not provided', function () {
    AssistantAgent::fake();

    (new AssistantAgent)->prompt('Hello');

    AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
        $options = TextGenerationOptions::forAgent($prompt->agent);

        return $options->providerOptions(Lab::OpenAI) === null;
    });
});
