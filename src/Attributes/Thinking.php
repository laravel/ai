<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Thinking
{
    /**
     * @param  bool  $enabled  Whether extended thinking is enabled (defaults to true when attribute is present).
     * @param  int|null  $budgetTokens  Maximum token budget for thinking (Anthropic: budget_tokens, Gemini: thinkingBudget).
     * @param  string|null  $effort  Reasoning effort level — typically "low", "medium", or "high" (OpenAI: reasoning_effort, Gemini: thinkingLevel).
     */
    public function __construct(public bool $enabled = true, public ?int $budgetTokens = null, public ?string $effort = null)
    {
        //
    }
}
