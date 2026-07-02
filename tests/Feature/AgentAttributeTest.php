<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\IncompatibleMethodsAgent;
use Tests\Fixtures\Agents\MethodOptionsAgent;
use Tests\Fixtures\Agents\MethodOverridesAttributeAgent;
use Tests\Fixtures\Agents\NamedAgent;
use Tests\Fixtures\Agents\NullableMethodOptionsAgent;
use Tests\Fixtures\Agents\ResearchAgent;

test('text generation options can be created from agent attributes', function () {
    $options = TextGenerationOptions::forAgent(new AttributeAgent);

    expect($options->maxSteps)->toBe(10)
        ->and($options->maxTokens)->toBe(4096)
        ->and($options->temperature)->toBe(0.7)
        ->and($options->topP)->toBe(0.8);
});

test('text generation options are null when agent has no attributes', function () {
    $options = TextGenerationOptions::forAgent(new AssistantAgent);

    expect($options->maxSteps)->toBeNull()
        ->and($options->maxTokens)->toBeNull()
        ->and($options->temperature)->toBeNull()
        ->and($options->topP)->toBeNull();
});

test('text generation options can be resolved from agent methods', function () {
    $options = TextGenerationOptions::forAgent(new MethodOptionsAgent);

    expect($options->maxSteps)->toBe(3)
        ->and($options->maxTokens)->toBe(2048)
        ->and($options->temperature)->toBe(0.5);
});

test('agent methods take priority over attributes', function () {
    $options = TextGenerationOptions::forAgent(new MethodOverridesAttributeAgent);

    expect($options->maxSteps)->toBe(1)
        ->and($options->maxTokens)->toBe(512)
        ->and($options->temperature)->toBe(0.2);
});

test('null return from agent method falls back to attributes', function () {
    $options = TextGenerationOptions::forAgent(new NullableMethodOptionsAgent);

    expect($options->maxSteps)->toBe(10)
        ->and($options->maxTokens)->toBe(4096)
        ->and($options->temperature)->toBe(0.7);
});

test('non-public or parameterized option methods are ignored', function () {
    $options = TextGenerationOptions::forAgent(new IncompatibleMethodsAgent);

    expect($options->maxSteps)->toBe(8)
        ->and($options->maxTokens)->toBe(1024)
        ->and($options->temperature)->toBe(0.9);
});

test('agent name defaults to class name', function () {
    expect((new AssistantAgent)->agentName())->toBe(AssistantAgent::class);
});

test('agent name can be set via the Name attribute', function () {
    expect((new NamedAgent)->agentName())->toBe('My Custom Agent');
});

test('agent name ignores the CanActAsTool name method', function () {
    expect((new ResearchAgent)->agentName())->toBe(ResearchAgent::class);
});

test('agent name can be overridden directly', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function agentName(): string
        {
            return 'Dynamic Agent';
        }
    };

    expect($agent->agentName())->toBe('Dynamic Agent');
});

test('provider attribute is used when prompting', function () {
    AttributeAgent::fake();

    (new AttributeAgent)->prompt('Hello');

    AttributeAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->provider->name() === Lab::Anthropic->value;
    });
});
