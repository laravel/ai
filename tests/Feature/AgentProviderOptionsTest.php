<?php

namespace Tests\Feature;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\TestCase;

class AgentProviderOptionsTest extends TestCase
{
    public function test_text_generation_options_can_extract_provider_options_for_openai(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::OpenAI);

        $this->assertNotNull($providerOptions);
        $this->assertIsArray($providerOptions);
        $this->assertEquals([
            'reasoning' => [
                'effort' => 'high',
            ],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ], $providerOptions);
    }

    public function test_text_generation_options_can_extract_provider_options_for_anthropic(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::Anthropic);

        $this->assertNotNull($providerOptions);
        $this->assertEquals([
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 10000,
            ],
        ], $providerOptions);
    }

    public function test_text_generation_options_accept_string_provider(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions('openai');

        $this->assertNotNull($providerOptions);
        $this->assertEquals([
            'reasoning' => [
                'effort' => 'high',
            ],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ], $providerOptions);
    }

    public function test_text_generation_options_return_empty_array_for_unknown_provider(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::Gemini);

        $this->assertEquals([], $providerOptions);
    }

    public function test_text_generation_options_have_null_provider_options_when_agent_does_not_implement_interface(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->providerOptions(Lab::OpenAI));
    }

    public function test_provider_options_are_passed_through_when_prompting(): void
    {
        ProviderOptionsAgent::fake();

        (new ProviderOptionsAgent)->prompt('Hello');

        ProviderOptionsAgent::assertPrompted(function (AgentPrompt $prompt) {
            $options = TextGenerationOptions::forAgent($prompt->agent);

            return $options->providerOptions(Lab::OpenAI) === [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ];
        });
    }

    public function test_provider_options_default_to_null_when_not_provided(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            $options = TextGenerationOptions::forAgent($prompt->agent);

            return $options->providerOptions(Lab::OpenAI) === null;
        });
    }
}
