<?php

namespace Laravel\Ai\Scheduling;

use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

class ScheduleMixin
{
    /**
     * Schedule an AI agent to be queued on a given cadence.
     */
    public function agent(): Closure
    {
        return function (
            Agent|string $agent,
            string $prompt = '',
            array $attachments = [],
            Lab|array|string|null $provider = null,
            ?string $model = null,
        ): PendingScheduledAgent {
            /** @var Schedule $this */
            $pending = new PendingScheduledAgent;

            $event = $this->call(function () use ($pending, $agent, $prompt, $attachments, $provider, $model) {
                $pending->report(
                    (is_string($agent) ? $agent::make() : $agent)->queue($prompt, $attachments, $provider, $model)
                );
            })->name('agent:'.class_basename(is_string($agent) ? $agent : $agent::class));

            return $pending->setEvent($event);
        };
    }
}
