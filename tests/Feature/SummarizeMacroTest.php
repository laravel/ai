<?php

use Illuminate\Support\Str;
use Laravel\Ai\Agents\SummarizeAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\OpenAiProvider;

test('summary can be generated from stringable macro', function (): void {
    SummarizeAgent::fake(['A short summary.']);

    $summary = Str::of('A very long piece of text that needs summarizing.')->summarize();

    expect($summary)->toBe('A short summary.');

    SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
        && str_contains($prompt->agent->instructions(), '3 sentences'));
});

test('summary honors requested sentence count', function (): void {
    SummarizeAgent::fake();

    str('Some text.')->summarize(sentences: 1);

    SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), '1 sentence')
        && ! str_contains($prompt->agent->instructions(), '1 sentences'));
});

test('summary uses the cheapest model by default', function (): void {
    SummarizeAgent::fake();

    str('Some text.')->summarize();

    SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === $prompt->provider->cheapestTextModel());
});

test('summary can be generated from static Str macro', function (): void {
    SummarizeAgent::fake(['A short summary.']);

    $summary = Str::summarize('A very long piece of text that needs summarizing.', sentences: 4);

    expect($summary)->toBe('A short summary.');

    SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
        && str_contains($prompt->agent->instructions(), '4 sentences'));
});

test('summary macro passes through options', function (): void {
    SummarizeAgent::fake();

    str('Some text.')->summarize(
        sentences: 4,
        provider: Lab::OpenAI,
        model: 'custom-model',
        timeout: 45,
    );

    SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Some text.'
        && $prompt->provider instanceof OpenAiProvider
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});
