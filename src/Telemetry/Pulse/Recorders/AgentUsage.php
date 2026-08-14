<?php

namespace Laravel\Ai\Telemetry\Pulse\Recorders;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Concerns\Sampling;

class AgentUsage
{
    use Sampling;

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
        if (! $this->shouldSample()) {
            return;
        }

        $key = $event->provider->name().':'.$event->model;

        if ($this->shouldIgnore($key)) {
            return;
        }

        $usage = $event->response->usage;

        // Counted here rather than on every type so the step count is not multiplied by the number of token buckets...
        Pulse::record('ai_prompt_tokens', $key, $usage->promptTokens)->sum()->count();
        Pulse::record('ai_completion_tokens', $key, $usage->completionTokens)->sum();
        Pulse::record('ai_cached_tokens', $key, $usage->cacheReadInputTokens)->sum();

        // Pulse only aggregates integers, and sub-millisecond provider calls do not exist...
        Pulse::record('ai_step_duration', $key, (int) round($event->time))->avg()->max()->count();
    }

    /**
     * Determine whether the given provider and model should not be recorded.
     */
    protected function shouldIgnore(string $key): bool
    {
        foreach (Config::get('pulse.recorders.'.static::class.'.ignore', []) as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }
}
