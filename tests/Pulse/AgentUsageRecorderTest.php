<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Telemetry\Pulse\Recorders\AgentUsage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;

function stepCompleted(Usage $usage, float $time = 1234.6, string $provider = 'groq', string $model = 'llama-3.3-70b'): StepCompleted
{
    $textProvider = Mockery::mock(TextProvider::class);
    $textProvider->shouldReceive('name')->andReturn($provider);

    return new StepCompleted(
        'inv_1',
        1,
        Mockery::mock(Agent::class),
        $textProvider,
        $model,
        true,
        new StepResponse('text', [], FinishReason::Stop, $usage, new Meta),
        $time,
    );
}

/**
 * Capture the entries the recorder hands to Pulse, keyed by type.
 *
 * @return array<string, Entry>
 */
function recordedEntries(StepCompleted $event): array
{
    $entries = [];

    Pulse::shouldReceive('record')->andReturnUsing(
        function (string $type, string $key, ?int $value = null) use (&$entries): Entry {
            return $entries[$type] = new Entry(timestamp: 0, type: $type, key: $key, value: $value);
        }
    );

    (new AgentUsage)->record($event);

    return $entries;
}

test('it records the token usage of a completed step against the provider and model', function (): void {
    $entries = recordedEntries(stepCompleted(new Usage(
        promptTokens: 900,
        completionTokens: 120,
        cacheReadInputTokens: 640,
    )));

    expect($entries['ai_prompt_tokens']->key)->toBe('groq:llama-3.3-70b')
        ->and($entries['ai_prompt_tokens']->value)->toBe(900)
        ->and($entries['ai_completion_tokens']->value)->toBe(120)
        ->and($entries['ai_cached_tokens']->value)->toBe(640);
});

test('it counts steps once rather than once per token bucket', function (): void {
    $entries = recordedEntries(stepCompleted(new Usage(promptTokens: 10, completionTokens: 5)));

    expect($entries['ai_prompt_tokens']->isCount())->toBeTrue()
        ->and($entries['ai_completion_tokens']->isCount())->toBeFalse()
        ->and($entries['ai_cached_tokens']->isCount())->toBeFalse();
});

test('it records the step duration as whole milliseconds', function (): void {
    $entries = recordedEntries(stepCompleted(new Usage, time: 1234.6));

    expect($entries['ai_step_duration']->value)->toBe(1235)
        ->and($entries['ai_step_duration']->isAvg())->toBeTrue()
        ->and($entries['ai_step_duration']->isMax())->toBeTrue()
        ->and($entries['ai_step_duration']->isCount())->toBeTrue();
});

test('it does not record models matching an ignore pattern', function (): void {
    config()->set('pulse.recorders.'.AgentUsage::class.'.ignore', ['#^groq:#']);

    expect(recordedEntries(stepCompleted(new Usage(promptTokens: 10))))->toBe([]);
});
