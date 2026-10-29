<?php

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Feature\Providers\ClaudeCli\ClaudeCliHelpers;
use Tests\Fixtures\Agents\AssistantAgent;

uses(ClaudeCliHelpers::class);

afterEach(function (): void {
    $this->cleanUpFakeCliScripts();
});

describe('streaming events', function (): void {
    test('emits StreamStart, TextStart, TextDelta, and TextEnd for a successful response', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hello'));
        $this->useCliBinary($binary);

        $events = $this->collectStreamEvents();

        expect($events)->toHaveCount(5)
            ->and($events[0])->toBeInstanceOf(StreamStart::class)
            ->and($events[1])->toBeInstanceOf(TextStart::class)
            ->and($events[2])->toBeInstanceOf(TextDelta::class)
            ->and($events[3])->toBeInstanceOf(TextEnd::class)
            ->and($events[4])->toBeInstanceOf(StreamEnd::class);
    });

    test('TextDelta carries the text from the assistant message', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('World'));
        $this->useCliBinary($binary);

        $events = $this->collectStreamEvents();

        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $text = implode('', array_map(fn ($e) => $e->delta, $deltas));

        expect($text)->toBe('World');
    });

    test('StreamStart carries the provider name and model', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hi', 'sess-xyz', 'claude-sonnet-5-5'));
        $this->useCliBinary($binary);

        $events = $this->collectStreamEvents();

        /** @var StreamStart $start */
        $start = collect($events)->first(fn ($e) => $e instanceof StreamStart);

        expect($start)->not->toBeNull()
            ->and($start->provider)->toBe('claude-cli')
            ->and($start->model)->toBe('claude-sonnet-5-5');
    });

    test('all events share the same invocation ID', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines());
        $this->useCliBinary($binary);

        $events = $this->collectStreamEvents();

        $ids = array_unique(array_map(fn ($e) => $e->invocationId, $events));

        expect($ids)->toHaveCount(1);
    });

    test('TextStart and TextEnd share the same message ID', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines());
        $this->useCliBinary($binary);

        $events = $this->collectStreamEvents();

        $textStart = collect($events)->first(fn ($e) => $e instanceof TextStart);
        $textEnd = collect($events)->first(fn ($e) => $e instanceof TextEnd);

        expect($textStart)->not->toBeNull()
            ->and($textEnd)->not->toBeNull()
            ->and($textStart->messageId)->toBe($textEnd->messageId);
    });
});
