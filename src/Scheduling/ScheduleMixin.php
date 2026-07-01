<?php

namespace Laravel\Ai\Scheduling;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Ai\Console\Commands\RunAgentCommand;
use Laravel\Ai\Enums\Lab;

class ScheduleMixin
{
    /**
     * Schedule an AI agent to run on a given cadence.
     */
    public function agent(): Closure
    {
        return function (
            string $agent,
            string $prompt = '',
            Lab|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): Event {
            /** @var Schedule $this */
            return $this->command(RunAgentCommand::class, array_filter([
                $agent,
                $prompt,
                '--provider' => $provider instanceof Lab ? $provider->value : $provider,
                '--model' => $model,
                '--timeout' => $timeout,
            ], fn ($value) => $value !== null && $value !== ''))
                ->name('agent:'.class_basename($agent));
        };
    }
}
