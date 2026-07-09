<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Enums\ToolChoiceMode;
use Laravel\Ai\ToolChoice as ToolChoiceValue;

#[Attribute(Attribute::TARGET_CLASS)]
class ToolChoice
{
    public ToolChoiceValue $value;

    public function __construct(ToolChoiceMode|string $mode, ?string $toolName = null)
    {
        $resolved = $mode instanceof ToolChoiceMode ? $mode : ToolChoiceMode::from($mode);

        $this->value = new ToolChoiceValue($resolved, $toolName);
    }
}
