<?php

use Laravel\Ai\Enums\ToolChoiceMode;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;

test('named constructors build the expected mode and tool name', function () {
    expect(ToolChoice::auto()->mode)->toBe(ToolChoiceMode::Auto)
        ->and(ToolChoice::auto()->toolName)->toBeNull()
        ->and(ToolChoice::none()->mode)->toBe(ToolChoiceMode::None)
        ->and(ToolChoice::required()->mode)->toBe(ToolChoiceMode::Required)
        ->and(ToolChoice::tool('calculator')->mode)->toBe(ToolChoiceMode::Tool)
        ->and(ToolChoice::tool('calculator')->toolName)->toBe('calculator');
});

test('tool mode requires a non-empty tool name', function () {
    expect(fn () => new ToolChoice(ToolChoiceMode::Tool))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ToolChoice(ToolChoiceMode::Tool, ''))->toThrow(InvalidArgumentException::class);
});

test('non-tool modes reject a tool name', function () {
    expect(fn () => new ToolChoice(ToolChoiceMode::Auto, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ToolChoice(ToolChoiceMode::Required, 'x'))->toThrow(InvalidArgumentException::class);
});

test('from coerces instances, enums, and strings', function () {
    $choice = ToolChoice::required();

    expect(ToolChoice::from($choice))->toBe($choice)
        ->and(ToolChoice::from(ToolChoiceMode::None)->mode)->toBe(ToolChoiceMode::None)
        ->and(ToolChoice::from('auto')->mode)->toBe(ToolChoiceMode::Auto)
        ->and(ToolChoice::from('required')->mode)->toBe(ToolChoiceMode::Required);
});

test('from accepts array shorthand for tool selection', function () {
    expect(ToolChoice::from(['tool' => 'calculator'])->toolName)->toBe('calculator')
        ->and(ToolChoice::from(['toolName' => 'calculator'])->toolName)->toBe('calculator')
        ->and(ToolChoice::from(['name' => 'calculator'])->toolName)->toBe('calculator');
});

test('from rejects invalid values', function () {
    expect(fn () => ToolChoice::from('bogus'))->toThrow(ValueError::class);
    expect(fn () => ToolChoice::from(['unexpected' => 'value']))->toThrow(InvalidArgumentException::class);
});

test('options resolve tool choice from the attribute', function () {
    $options = TextGenerationOptions::forAgent(new AttributeToolChoiceAgent);

    expect($options->toolChoice)->not->toBeNull()
        ->and($options->toolChoice->mode)->toBe(ToolChoiceMode::Required);
});

test('options resolve tool choice from the method over the attribute', function () {
    $options = TextGenerationOptions::forAgent(new ToolChoiceAgent(ToolChoice::tool('custom_named_tool')));

    expect($options->toolChoice->mode)->toBe(ToolChoiceMode::Tool)
        ->and($options->toolChoice->toolName)->toBe('custom_named_tool');
});

test('options coerce a plain string from the method', function () {
    $options = TextGenerationOptions::forAgent(new ToolChoiceAgent('required'));

    expect($options->toolChoice->mode)->toBe(ToolChoiceMode::Required);
});

test('options leave tool choice null when the agent declares none', function () {
    expect(TextGenerationOptions::forAgent(new AssistantAgent)->toolChoice)->toBeNull();
    expect(TextGenerationOptions::forAgent(new ToolChoiceAgent)->toolChoice)->toBeNull();
});
