<?php

use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\SecondaryAssistantAgent;

beforeEach(function (): void {
    requiresApiKey('GROQ_API_KEY');

    $this->provider = 'groq';
    $this->model = 'openai/gpt-oss-20b';
});

test('faking one agent doesnt affect another agent', function (): void {
    AssistantAgent::fake(fn (): string => 'Fake response');

    $fakeResponse = (new AssistantAgent)->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    );

    $realResponse = (new SecondaryAssistantAgent)->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    );

    expect($fakeResponse->text)->toEqual('Fake response')
        ->and(str_contains($realResponse->text, 'Laravel'))->toBeTrue();
});
