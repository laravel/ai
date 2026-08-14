<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Telemetry\Pulse\Livewire\StepLatency;
use Laravel\Ai\Telemetry\Pulse\Livewire\TokenUsage;
use Laravel\Pulse\Contracts\Storage;
use Livewire\Livewire;

function fakePulseStorage(string $method, Collection $rows): void
{
    // The cards are lazy, so without this a test only ever sees the placeholder...
    Livewire::withoutLazyLoading();

    $storage = Mockery::mock(Storage::class);
    $storage->shouldReceive($method)->andReturn($rows);
    $storage->shouldIgnoreMissing(new Collection);

    app()->instance(Storage::class, $storage);
}

test('the token usage card lists each model with its input, output, and cached tokens', function (): void {
    fakePulseStorage('aggregateTypes', new Collection([
        (object) [
            'key' => 'groq:llama-3.3-70b',
            'ai_prompt_tokens' => 1200,
            'ai_completion_tokens' => 340,
            'ai_cached_tokens' => 900,
        ],
    ]));

    Livewire::test(TokenUsage::class)
        ->assertOk()
        ->assertSee('AI Token Usage')
        ->assertSee('groq:llama-3.3-70b')
        ->assertSee('1,200')
        ->assertSee('340')
        ->assertSee('900');
});

test('the step latency card lists each model with its step count, average, and slowest step', function (): void {
    fakePulseStorage('aggregate', new Collection([
        (object) [
            'key' => 'openai:gpt-5',
            'count' => 42,
            'avg' => 1500,
            'max' => 9100,
        ],
    ]));

    Livewire::test(StepLatency::class)
        ->assertOk()
        ->assertSee('AI Step Latency')
        ->assertSee('openai:gpt-5')
        ->assertSee('42')
        ->assertSee('1,500')
        ->assertSee('9,100');
});

test('the cards render an empty state when nothing has been recorded', function (): void {
    fakePulseStorage('aggregateTypes', new Collection);

    Livewire::test(TokenUsage::class)->assertOk()->assertSee('No results');
});
