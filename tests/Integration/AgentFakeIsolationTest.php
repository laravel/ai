<?php

use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\SecondaryAssistantAgent;

beforeEach(function () {
    requiresApiKey('GROQ_API_KEY');

    $this->provider = 'groq';
    $this->model = 'openai/gpt-oss-20b';
});

test('faking one agent doesnt affect another agent', function () {
    AssistantAgent::fake(fn () => 'Fake response');

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
