<?php

namespace Laravel\Ai\Agents;

use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
final class SummarizeConversationAgent implements Agent
{
    use Promptable;

    public function __construct(protected ?string $summary = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return Str::of('Summarize the given conversation for use as the memory of an ongoing chat. Preserve decisions, facts, identifiers, and unresolved questions. Drop pleasantries. Respond with only the summary and nothing else.')
            ->when($this->summary, fn (Stringable $instructions): Stringable => $instructions->append(PHP_EOL.PHP_EOL.'Summary of the conversation so far:'.PHP_EOL.$this->summary))
            ->toString();
    }
}
