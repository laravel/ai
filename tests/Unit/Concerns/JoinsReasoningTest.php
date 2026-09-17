<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Concerns\JoinsReasoning;
use Laravel\Ai\Streaming\Events\ReasoningDelta;

$join = new class
{
    use JoinsReasoning;

    public function __invoke(iterable $blocks): string
    {
        return self::joinReasoning($blocks);
    }
};

test('it separates each block with a blank line', function () use ($join): void {
    expect($join(['First.', 'Second.']))->toBe("First.\n\nSecond.");
});

test('it drops blocks that hold no text', function () use ($join): void {
    expect($join(['First.', '', '   ', "\n", 'Second.']))->toBe("First.\n\nSecond.");
});

test('it joins a collection the same way it joins an array', function () use ($join): void {
    expect($join(new Collection(['First.', 'Second.'])))->toBe("First.\n\nSecond.");
});

test('combining reasoning deltas applies the same rule', function (): void {
    $events = [
        new ReasoningDelta('e1', 'rs_1', 'Let me ', 0),
        new ReasoningDelta('e2', 'rs_1', 'think...', 0),
        new ReasoningDelta('e3', 'rs_2', '   ', 0),
        new ReasoningDelta('e4', 'rs_3', 'Now I am sure.', 0),
    ];

    expect(ReasoningDelta::combine($events))->toBe("Let me think...\n\nNow I am sure.");
});
