<?php

namespace Tests\Feature;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\TestCase;

class AgentProviderOptionsTest extends TestCase
{
    public function test_text_generation_options_can_be_created_from_agent_with_provider_options(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $this->assertNotNull($options->providerOptions);
        $this->assertIsArray($options->providerOptions);
        $this->assertEquals([
            'reasoning' => [
                'effort' => 'high',
            ],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ], $options->providerOptions);
    }

    public function test_text_generation_options_have_null_provider_options_when_agent_does_not_implement_interface(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->providerOptions);
    }

    public function test_provider_options_are_passed_through_when_prompting(): void
    {
        ProviderOptionsAgent::fake();

        (new ProviderOptionsAgent)->prompt('Hello');

        ProviderOptionsAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->options->providerOptions === [
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
            return $prompt->options->providerOptions === null;
        });
    }
}
