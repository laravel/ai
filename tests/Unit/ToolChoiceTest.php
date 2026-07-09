<?php

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;

test('constructor and tool() build the expected mode and tool name', function () {
    expect((new ToolChoice(ToolChoice::auto))->mode)->toBe(ToolChoice::auto)
        ->and((new ToolChoice(ToolChoice::auto))->toolName)->toBeNull()
        ->and((new ToolChoice(ToolChoice::none))->mode)->toBe(ToolChoice::none)
        ->and((new ToolChoice(ToolChoice::required))->mode)->toBe(ToolChoice::required)
        ->and(ToolChoice::tool('calculator')->mode)->toBe(ToolChoice::tool)
        ->and(ToolChoice::tool('calculator')->toolName)->toBe('calculator');
});

test('tool mode requires a non-empty tool name', function () {
    expect(fn () => new ToolChoice(ToolChoice::tool))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ToolChoice(ToolChoice::tool, ''))->toThrow(InvalidArgumentException::class);
});

test('non-tool modes reject a tool name', function () {
    expect(fn () => new ToolChoice(ToolChoice::auto, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ToolChoice(ToolChoice::required, 'x'))->toThrow(InvalidArgumentException::class);
});

test('from coerces instances and strings', function () {
    $choice = new ToolChoice(ToolChoice::required);

    expect(ToolChoice::from($choice))->toBe($choice)
        ->and(ToolChoice::from('auto')->mode)->toBe(ToolChoice::auto)
        ->and(ToolChoice::from('required')->mode)->toBe(ToolChoice::required);
});

test('from accepts array shorthand for tool selection', function () {
    expect(ToolChoice::from(['tool' => 'calculator'])->toolName)->toBe('calculator')
        ->and(ToolChoice::from(['toolName' => 'calculator'])->toolName)->toBe('calculator')
        ->and(ToolChoice::from(['name' => 'calculator'])->toolName)->toBe('calculator');
});

test('from rejects invalid values', function () {
    expect(fn () => ToolChoice::from('bogus'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ToolChoice::from(['unexpected' => 'value']))->toThrow(InvalidArgumentException::class);
});

test('options resolve tool choice from the attribute', function () {
    $options = TextGenerationOptions::forAgent(new AttributeToolChoiceAgent);

    expect($options->toolChoice)->not->toBeNull()
        ->and($options->toolChoice->mode)->toBe(ToolChoice::required);
});

test('options resolve tool choice from the method over the attribute', function () {
    $options = TextGenerationOptions::forAgent(new ToolChoiceAgent(ToolChoice::tool('custom_named_tool')));

    expect($options->toolChoice->mode)->toBe(ToolChoice::tool)
        ->and($options->toolChoice->toolName)->toBe('custom_named_tool');
});

test('options coerce a plain string from the method', function () {
    $options = TextGenerationOptions::forAgent(new ToolChoiceAgent('required'));

    expect($options->toolChoice->mode)->toBe(ToolChoice::required);
});

test('options leave tool choice null when the agent declares none', function () {
    expect(TextGenerationOptions::forAgent(new AssistantAgent)->toolChoice)->toBeNull();
    expect(TextGenerationOptions::forAgent(new ToolChoiceAgent)->toolChoice)->toBeNull();
});
