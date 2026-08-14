<?php

namespace Laravel\Ai\Telemetry\Pulse\Recorders;

use Laravel\Ai\Events\StepCompleted;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Concerns\Ignores;

class AgentUsage
{
    use Ignores;

    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        StepCompleted::class,
    ];

    /**
     * Record the token usage and latency of a completed generation step.
     */
    public function record(StepCompleted $event): void
    {
        $key = $event->provider->name().':'.$event->model;

        if ($this->shouldIgnore($key)) {
            return;
        }

        $usage = $event->response->usage;

        Pulse::record('ai_prompt_tokens', $key, $usage->promptTokens)->sum();
        Pulse::record('ai_completion_tokens', $key, $usage->completionTokens)->sum();
        Pulse::record('ai_cached_tokens', $key, $usage->cacheReadInputTokens)->sum();
        Pulse::record('ai_step_duration', $key, (int) round($event->time))->avg()->max()->count();
    }
}
