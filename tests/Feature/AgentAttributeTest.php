<?php

namespace Tests\Feature;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\ThinkingAgent;
use Tests\Feature\Agents\ThinkingFullAgent;
use Tests\Feature\Agents\ThinkingWithEffortAgent;
use Tests\TestCase;

class AgentAttributeTest extends TestCase
{
    public function test_text_generation_options_can_be_created_from_agent_attributes(): void
    {
        $options = TextGenerationOptions::forAgent(new AttributeAgent);

        $this->assertSame(10, $options->maxSteps);
        $this->assertSame(4096, $options->maxTokens);
        $this->assertSame(0.7, $options->temperature);
    }

    public function test_text_generation_options_are_null_when_agent_has_no_attributes(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->maxSteps);
        $this->assertNull($options->maxTokens);
        $this->assertNull($options->temperature);
    }

    public function test_thinking_attribute_with_budget_tokens_is_parsed(): void
    {
        $options = TextGenerationOptions::forAgent(new ThinkingAgent);

        $this->assertNotNull($options->thinking);
        $this->assertTrue($options->thinking['enabled']);
        $this->assertSame(10000, $options->thinking['budgetTokens']);
        $this->assertNull($options->thinking['effort']);
    }

    public function test_thinking_attribute_with_effort_is_parsed(): void
    {
        $options = TextGenerationOptions::forAgent(new ThinkingWithEffortAgent);

        $this->assertNotNull($options->thinking);
        $this->assertTrue($options->thinking['enabled']);
        $this->assertNull($options->thinking['budgetTokens']);
        $this->assertSame('high', $options->thinking['effort']);
    }

    public function test_thinking_attribute_with_all_options_is_parsed(): void
    {
        $options = TextGenerationOptions::forAgent(new ThinkingFullAgent);

        $this->assertNotNull($options->thinking);
        $this->assertTrue($options->thinking['enabled']);
        $this->assertSame(8000, $options->thinking['budgetTokens']);
        $this->assertSame('medium', $options->thinking['effort']);
    }

    public function test_thinking_is_null_when_agent_has_no_thinking_attribute(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->thinking);
    }

    public function test_provider_attribute_is_used_when_prompting(): void
    {
        AttributeAgent::fake();

        (new AttributeAgent)->prompt('Hello');

        AttributeAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->provider->name() === \Laravel\Ai\Enums\Lab::Anthropic->value;
        });
    }

    public function test_thinking_options_are_correct_for_deepseek_scenario(): void
    {
        // DeepSeek uses only enabled flag — no budget, no effort
        $options = TextGenerationOptions::forAgent(new ThinkingAgent);

        $this->assertNotNull($options->thinking);
        $this->assertTrue($options->thinking['enabled']);
        $this->assertSame(10000, $options->thinking['budgetTokens']);
        $this->assertNull($options->thinking['effort']);
    }

    public function test_thinking_options_are_correct_for_groq_scenario(): void
    {
        // Groq uses reasoning_format + optional reasoning_effort
        $options = TextGenerationOptions::forAgent(new ThinkingWithEffortAgent);

        $this->assertNotNull($options->thinking);
        $this->assertTrue($options->thinking['enabled']);
        $this->assertSame('high', $options->thinking['effort']);
        $this->assertNull($options->thinking['budgetTokens']);
    }

    public function test_thinking_enabled_flag_is_present_for_toggle_providers(): void
    {
        // Mistral and OpenRouter only use enabled flag
        $options = TextGenerationOptions::forAgent(new ThinkingAgent);

        $this->assertTrue($options->thinking['enabled']);
    }

    public function test_thinking_effort_maps_to_reasoning_effort_for_azure_and_openai(): void
    {
        // Azure OpenAI and OpenAI both use reasoning.effort
        $options = TextGenerationOptions::forAgent(new ThinkingWithEffortAgent);

        $this->assertSame('high', $options->thinking['effort']);
    }
}
