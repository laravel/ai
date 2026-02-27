<?php

namespace Tests\Feature;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\MethodOptionsAgent;
use Tests\Feature\Agents\MethodOverridesAttributeAgent;
use Tests\TestCase;

class TextGenerationOptionsTest extends TestCase
{
    public function test_text_generation_options_can_be_resolved_from_agent_methods(): void
    {
        $options = TextGenerationOptions::forAgent(new MethodOptionsAgent);

        $this->assertSame(3, $options->maxSteps);
        $this->assertSame(2048, $options->maxTokens);
        $this->assertSame(0.5, $options->temperature);
    }

    public function test_agent_methods_take_priority_over_attributes(): void
    {
        $options = TextGenerationOptions::forAgent(new MethodOverridesAttributeAgent);

        $this->assertSame(1, $options->maxSteps);
        $this->assertSame(512, $options->maxTokens);
        $this->assertSame(0.2, $options->temperature);
    }

    public function test_options_are_null_when_agent_has_no_methods_or_attributes(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->maxSteps);
        $this->assertNull($options->maxTokens);
        $this->assertNull($options->temperature);
    }
}
