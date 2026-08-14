<?php

namespace Laravel\Ai\Telemetry\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class StepLatency extends Card
{
    /**
     * Render the card.
     */
    public function render(): Renderable
    {
        [$models, $time, $runAt] = $this->remember(
            fn () => $this->aggregate('ai_step_duration', ['max', 'avg', 'count'], 'max'),
        );

        return View::make('ai::pulse.step-latency', [
            'models' => $models,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
