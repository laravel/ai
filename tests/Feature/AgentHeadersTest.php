<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

test('text generation options can extract headers for openai', function (): void {
    $options = TextGenerationOptions::forAgent(new HeadersAgent);

    $headers = $options->headers(Lab::OpenAI);

    expect($headers)->not->toBeNull()
        ->toBeArray()
        ->toEqual([
            'X-Custom-Header' => 'openai-value',
            'X-Request-Source' => 'laravel-ai',
        ]);
});

test('text generation options can extract headers for anthropic', function (): void {
    $options = TextGenerationOptions::forAgent(new HeadersAgent);

    $headers = $options->headers(Lab::Anthropic);

    expect($headers)->not->toBeNull()
        ->toEqual(['X-Custom-Header' => 'anthropic-value']);
});

test('text generation options accept string provider', function (): void {
    $options = TextGenerationOptions::forAgent(new HeadersAgent);

    $headers = $options->headers('openai');

    expect($headers)->not->toBeNull()
        ->toEqual([
            'X-Custom-Header' => 'openai-value',
            'X-Request-Source' => 'laravel-ai',
        ]);
});

test('text generation options return empty array for unknown provider', function (): void {
    $options = TextGenerationOptions::forAgent(new HeadersAgent);

    $headers = $options->headers(Lab::Cohere);

    expect($headers)->toBeEmpty();
});

test('text generation options have null headers when agent does not implement interface', function (): void {
    $options = TextGenerationOptions::forAgent(new AssistantAgent);

    expect($options->headers(Lab::OpenAI))->toBeNull();
});

test('headers are passed through when prompting', function (): void {
    HeadersAgent::fake();

    (new HeadersAgent)->prompt('Hello');

    HeadersAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $options = TextGenerationOptions::forAgent($prompt->agent);

        return $options->headers(Lab::OpenAI) === [
            'X-Custom-Header' => 'openai-value',
            'X-Request-Source' => 'laravel-ai',
        ];
    });
});

test('headers default to null when not provided', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent)->prompt('Hello');

    AssistantAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $options = TextGenerationOptions::forAgent($prompt->agent);

        return $options->headers(Lab::OpenAI) === null;
    });
});
