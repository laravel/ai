<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\ToolUsingAgent;

class RequestMappingTest extends GeminiTestCase
{
    public function test_request_includes_model_in_url_and_contents(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'gemini',
            model: 'gemini-3-flash-preview',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-3-flash-preview:generateContent')
                && $request->data()['contents'][0]['role'] === 'user'
                && $request->data()['contents'][0]['parts'][0]['text'] === 'What is Laravel?';
        });
    }

    public function test_system_instructions_are_sent_as_system_instruction_field(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system_instruction'])
                && isset($body['system_instruction']['parts'][0]['text'])
                && str_contains($body['system_instruction']['parts'][0]['text'], 'helpful');
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_config']);
        });
    }

    public function test_request_sends_api_key_header(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Laravel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'gemini',
        );

        $this->assertSame('Laravel is a PHP framework', $response->text);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Hello']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 25,
                    'candidatesTokenCount' => 15,
                    'totalTokenCount' => 40,
                    'cachedContentTokenCount' => 5,
                    'thoughtsTokenCount' => 10,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertSame(20, $response->usage->promptTokens);
        $this->assertSame(15, $response->usage->completionTokens);
        $this->assertSame(5, $response->usage->cacheReadInputTokens);
        $this->assertSame(10, $response->usage->reasoningTokens);
    }

    public function test_structured_output_uses_response_schema(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $config = $body['generationConfig'] ?? [];

            return ($config['response_mime_type'] ?? '') === 'application/json'
                && isset($config['response_schema']);
        });
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        $response = (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        $this->assertSame('Fe', $response->structured['symbol']);
    }

    public function test_tools_include_tool_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && isset($body['tool_config'])
                && $body['tool_config']['function_calling_config']['mode'] === 'AUTO';
        });
    }
}
