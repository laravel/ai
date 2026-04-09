<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_usage_without_cached_tokens_uses_full_prompt_count(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Hi']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 100,
                    'candidatesTokenCount' => 50,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(100, $response->usage->promptTokens);
        $this->assertSame(50, $response->usage->completionTokens);
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

    public function test_function_call_id_is_extracted_from_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $steps = $response->steps;

        $this->assertNotEmpty($steps);

        $toolCall = $steps->first()->toolCalls[0] ?? null;

        $this->assertNotNull($toolCall);
        $this->assertSame('call_abc123', $toolCall->id);
    }

    public function test_structured_response_schema_excludes_additional_properties(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt('Iron symbol?', provider: 'gemini');

        Http::assertSent(function ($request) {
            $schema = $request->data()['generationConfig']['response_schema'] ?? [];

            return ! isset($schema['additionalProperties'])
                && ! isset($schema['name']);
        });
    }

    public function test_thinking_response_parts_are_separated_from_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Internal reasoning...', 'thought' => true],
                            ['text' => 'The answer is 42.'],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 20,
                    'thoughtsTokenCount' => 15,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Question?', provider: 'gemini');

        $this->assertSame('The answer is 42.', $response->text);
        $this->assertStringNotContainsString('Internal reasoning', $response->text);
    }

    public function test_grounding_metadata_citations_are_filtered_through_supports(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Spain won Euro 2024.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://example.com/euro', 'title' => 'Euro 2024']],
                            ['web' => ['uri' => 'https://example.com/unreferenced', 'title' => 'Not Cited']],
                            ['web' => ['uri' => 'https://example.com/spain', 'title' => 'Spain Wins']],
                        ],
                        'groundingSupports' => [
                            ['segment' => ['startIndex' => 0, 'endIndex' => 20, 'text' => 'Spain won Euro 2024.'], 'groundingChunkIndices' => [0, 2]],
                        ],
                        'webSearchQueries' => ['who won euro 2024'],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Who won Euro 2024?', provider: 'gemini');

        $this->assertCount(2, $response->meta->citations);
        $this->assertSame('https://example.com/euro', $response->meta->citations[0]->url);
        $this->assertSame('https://example.com/spain', $response->meta->citations[1]->url);
    }

    public function test_legacy_citation_metadata_is_also_extracted(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Some content.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'citationMetadata' => [
                        'citationSources' => [
                            ['uri' => 'https://example.com/source1', 'title' => 'Source 1'],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        $this->assertCount(1, $response->meta->citations);
        $this->assertSame('https://example.com/source1', $response->meta->citations[0]->url);
    }

    public function test_duplicate_citations_are_deduplicated_by_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Content.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://example.com/same', 'title' => 'Title A']],
                            ['web' => ['uri' => 'https://example.com/same', 'title' => 'Title B']],
                        ],
                        'groundingSupports' => [
                            ['segment' => ['startIndex' => 0, 'endIndex' => 8], 'groundingChunkIndices' => [0, 1]],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        $this->assertCount(1, $response->meta->citations);
    }

    public static function finishReasonProvider(): array
    {
        return [
            'STOP maps to Stop' => ['STOP', FinishReason::Stop],
            'MAX_TOKENS maps to Length' => ['MAX_TOKENS', FinishReason::Length],
            'SAFETY maps to ContentFilter' => ['SAFETY', FinishReason::ContentFilter],
            'MALFORMED_FUNCTION_CALL maps to ContentFilter' => ['MALFORMED_FUNCTION_CALL', FinishReason::ContentFilter],
            'RECITATION maps to ContentFilter' => ['RECITATION', FinishReason::ContentFilter],
        ];
    }

    #[DataProvider('finishReasonProvider')]
    public function test_finish_reason_maps_correctly(string $geminiReason, FinishReason $expected): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Response']], 'role' => 'model'],
                    'finishReason' => $geminiReason,
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame($expected, $response->steps->last()->finishReason);
    }
}
