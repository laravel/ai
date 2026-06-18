<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Exceptions\BudgetExceededException;
use Laravel\Ai\Pricing\BudgetMeter;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\TextResponse;

class EnforceBudget
{
    /**
     * Create a new budget-enforcing middleware instance.
     */
    public function __construct(
        protected BudgetMeter $meter,
        protected float $limit,
    ) {}

    /**
     * Handle the incoming prompt, blocking it once the agent's budget is exhausted.
     *
     * Enforcement is pre-flight against accumulated spend within the current
     * process: once an agent has spent its limit, the next prompt is rejected
     * before any model call is made. Costs for models without known pricing
     * record as zero, so an unknown-priced model never trips the budget.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $key = $prompt->agent::class;

        if ($this->meter->spent($key) >= $this->limit) {
            throw BudgetExceededException::forAgent($key, $this->meter->spent($key), $this->limit);
        }

        $response = $next($prompt);

        if ($response instanceof TextResponse) {
            $this->meter->record($key, $response->cost());
        }

        return $response;
    }
}
