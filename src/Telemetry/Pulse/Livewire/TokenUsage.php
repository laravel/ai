<?php

namespace Laravel\Ai\Telemetry\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class TokenUsage extends Card
{
    /**
     * Render the card.
     */
    public function render(): Renderable
    {
        [$models, $time, $runAt] = $this->remember(fn () => $this->aggregateTypes([
            'ai_prompt_tokens',
            'ai_completion_tokens',
            'ai_cached_tokens',
        ], 'sum'));

        return View::make('ai::pulse.token-usage', [
            'models' => $models,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
