<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\AgentTool;

test('name uses agent name method when available', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function name(): string
        {
            return 'custom_agent';
        }

        public function instructions(): string
        {
            return 'Test instructions.';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->name())->toBe('custom_agent');
});

test('name falls back to class basename', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->name())->not->toBeEmpty();
});

test('description uses agent description method when available', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function description(): string
        {
            return 'A specialized research agent.';
        }

        public function instructions(): string
        {
            return 'You are a research agent with very long instructions.';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->description())->toBe('A specialized research agent.');
});

test('description falls back to instructions', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->description())->toBe('You are a helpful assistant.');
});

test('schema returns task string parameter', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('task');
});

test('agent returns underlying agent', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->agent())->toBe($agent);
});
