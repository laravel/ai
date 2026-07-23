<?php

namespace Laravel\Ai\Agents;

use Illuminate\Support\Str;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
final class SummarizeAgent implements Agent
{
    use Promptable;

    protected int $sentences;

    public function __construct(int $sentences = 3)
    {
        $this->sentences = max(1, $sentences);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return sprintf(
            'Summarize the given text in no more than %d %s. Respond with only the summary and nothing else.',
            $this->sentences,
            Str::plural('sentence', $this->sentences),
        );
    }
}
