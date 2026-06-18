<?php

namespace Laravel\Ai\Exceptions;

class BudgetExceededException extends AiException
{
    /**
     * Create an exception for an agent that has exhausted its cost budget.
     */
    public static function forAgent(string $agent, float $spent, float $limit): self
    {
        return new self(sprintf(
            'Agent [%s] has exceeded its cost budget of $%s (spent $%s).',
            $agent,
            number_format($limit, 4),
            number_format($spent, 4),
        ));
    }
}
