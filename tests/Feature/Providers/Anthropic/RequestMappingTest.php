<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\StructuredWithThinkingAgent;
use Tests\Feature\Agents\ToolUsingAgent;

class RequestMappingTest extends AnthropicTestCase
{
    public function test_request_includes_model_and_messages(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['model'] === 'claude-sonnet-4-6'
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['text'] === 'What is Laravel?';
        });
    }

    public function test_system_instructions_are_sent_as_top_level_system_field(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system'])
                && is_string($body['system'])
                && str_contains($body['system'], 'helpful');
        });
    }

    public function test_max_tokens_defaults_to_64000(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return $request->data()['max_tokens'] === 64000;
        });
    }

    public function test_tools_with_structured_output_use_tool_choice_any(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && count($body['tools']) > 0
                && $body['tool_choice']['type'] === 'any';
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_choice']);
        });
    }

    public function test_structured_output_uses_synthetic_tool(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'tool'
                && $body['tool_choice']['name'] === 'output_structured_data';
        });
    }

    public function test_request_sends_correct_authentication_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
        );

        $this->assertSame('Laravel is a PHP framework', $response->text);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 25,
                    'output_tokens' => 15,
                    'cache_creation_input_tokens' => 5,
                    'cache_read_input_tokens' => 3,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        $this->assertSame(25, $response->usage->promptTokens);
        $this->assertSame(15, $response->usage->completionTokens);
    }

    public function test_structured_output_with_thinking_uses_auto_tool_choice(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredWithThinkingAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'auto'
                && $body['thinking']['type'] === 'enabled';
        });
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        $this->assertSame('Taylor', $response->structured['name']);
        $this->assertSame(30, $response->structured['age']);
    }
}
