<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamStart;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

/**
 * Tests verifying compliance with the Gemini API documentation.
 *
 * @see https://ai.google.dev/gemini-api/docs
 */
class ApiComplianceTest extends GeminiTestCase
{
    // ─── Text Generation ────────────────────────────────────────────

    public function test_text_request_uses_contents_array_with_parts(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        agent('Be helpful.')->prompt('Hello', provider: 'gemini');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['contents'][0]['parts'][0]['text'])
                && $body['contents'][0]['parts'][0]['text'] === 'Hello';
        });
    }

    public function test_user_messages_use_user_role(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        agent('Be helpful.')->prompt('Hi', provider: 'gemini');

        Http::assertSent(function ($request) {
            return $request->data()['contents'][0]['role'] === 'user';
        });
    }

    // ─── System Instruction ─────────────────────────────────────────

    public function test_system_instruction_uses_snake_case_field_name(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system_instruction']['parts'][0]['text'])
                && ! isset($body['systemInstruction']);
        });
    }

    public function test_system_instruction_is_separate_from_contents(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        Http::assertSent(function ($request) {
            $body = $request->data();

            foreach ($body['contents'] as $content) {
                if (($content['role'] ?? '') === 'system') {
                    return false;
                }
            }

            return isset($body['system_instruction']);
        });
    }

    // ─── Function Calling ───────────────────────────────────────────

    public function test_tools_use_function_declarations_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('42'),
        ]);

        agent('You generate numbers.', tools: [new FixedNumberGenerator])
            ->prompt('Generate', provider: 'gemini');

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            return isset($tools[0]['function_declarations']);
        });
    }

    public function test_function_declaration_includes_name_and_description(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('42'),
        ]);

        agent('You generate numbers.', tools: [new FixedNumberGenerator])
            ->prompt('Generate', provider: 'gemini');

        Http::assertSent(function ($request) {
            $decls = $request->data()['tools'][0]['function_declarations'] ?? [];

            foreach ($decls as $decl) {
                if ($decl['name'] === 'FixedNumberGenerator') {
                    return isset($decl['description'])
                        && is_string($decl['description']);
                }
            }

            return false;
        });
    }

    public function test_tool_config_uses_function_calling_config_with_auto_mode(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('42'),
        ]);

        agent('You generate numbers.', tools: [new FixedNumberGenerator])
            ->prompt('Generate', provider: 'gemini');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tool_config']['function_calling_config']['mode'])
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

    public function test_function_response_includes_id_for_gemini_3(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $functionResponsePart = null;

        foreach ($followUpContents as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse'])) {
                    $functionResponsePart = $part['functionResponse'];
                }
            }
        }

        $this->assertNotNull($functionResponsePart, 'Follow-up should include functionResponse');
        $this->assertSame('FixedNumberGenerator', $functionResponsePart['name']);
        $this->assertArrayHasKey('id', $functionResponsePart);
        $this->assertArrayHasKey('response', $functionResponsePart);
    }

    public function test_function_response_contains_name_and_content_in_response_object(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();
        $followUpContents = $recorded[1][0]->data()['contents'];

        $responseObj = null;

        foreach ($followUpContents as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse']['response'])) {
                    $responseObj = $part['functionResponse']['response'];
                }
            }
        }

        $this->assertNotNull($responseObj);
        $this->assertArrayHasKey('name', $responseObj);
        $this->assertArrayHasKey('content', $responseObj);
    }

    public function test_parallel_function_calls_preserve_unique_ids(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [
                                ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                                ['functionCall' => ['id' => 'call_2', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate two', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $functionResponseIds = [];

        foreach ($followUpContents as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse']['id'])) {
                    $functionResponseIds[] = $part['functionResponse']['id'];
                }
            }
        }

        $this->assertCount(2, $functionResponseIds);
        $this->assertContains('call_1', $functionResponseIds);
        $this->assertContains('call_2', $functionResponseIds);
    }

    // ─── Structured Output ──────────────────────────────────────────

    public function test_structured_output_sets_response_mime_type_to_json(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt('Iron symbol?', provider: 'gemini');

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];

            return ($config['response_mime_type'] ?? '') === 'application/json';
        });
    }

    public function test_structured_output_includes_response_schema(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt('Iron symbol?', provider: 'gemini');

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];

            return isset($config['response_schema'])
                && isset($config['response_schema']['type']);
        });
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

    // ─── Thinking / Reasoning ───────────────────────────────────────

    public function test_thinking_config_is_passed_in_generation_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new ProviderOptionsAgent)->prompt('Hi', provider: 'gemini');

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];

            return isset($config['thinkingConfig']);
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

    public function test_thoughts_token_count_is_extracted(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Answer']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                    'thoughtsTokenCount' => 100,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Think', provider: 'gemini');

        $this->assertSame(100, $response->usage->reasoningTokens);
    }

    // ─── Grounding / Citations ──────────────────────────────────────

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

    public function test_unreferenced_grounding_chunks_are_excluded_from_citations(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Answer.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://example.com/a', 'title' => 'A']],
                            ['web' => ['uri' => 'https://example.com/b', 'title' => 'B']],
                            ['web' => ['uri' => 'https://example.com/c', 'title' => 'C']],
                        ],
                        'groundingSupports' => [
                            ['segment' => ['startIndex' => 0, 'endIndex' => 7], 'groundingChunkIndices' => [1]],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        $this->assertCount(1, $response->meta->citations);
        $this->assertSame('https://example.com/b', $response->meta->citations[0]->url);
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

    // ─── Usage Metadata ─────────────────────────────────────────────

    public function test_usage_metadata_maps_all_token_fields(): void
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
                    'cachedContentTokenCount' => 20,
                    'thoughtsTokenCount' => 30,
                    'totalTokenCount' => 180,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(80, $response->usage->promptTokens);
        $this->assertSame(50, $response->usage->completionTokens);
        $this->assertSame(20, $response->usage->cacheReadInputTokens);
        $this->assertSame(30, $response->usage->reasoningTokens);
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

    // ─── Finish Reasons ─────────────────────────────────────────────

    public function test_stop_finish_reason_maps_correctly(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::Stop, $response->steps->last()->finishReason);
    }

    public function test_max_tokens_finish_reason_maps_to_length(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Truncated']], 'role' => 'model'],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::Length, $response->steps->last()->finishReason);
    }

    public function test_safety_finish_reason_maps_to_content_filter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '']], 'role' => 'model'],
                    'finishReason' => 'SAFETY',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 0],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::ContentFilter, $response->steps->last()->finishReason);
    }

    public function test_malformed_function_call_maps_to_content_filter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '']], 'role' => 'model'],
                    'finishReason' => 'MALFORMED_FUNCTION_CALL',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 0],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::ContentFilter, $response->steps->last()->finishReason);
    }

    public function test_recitation_finish_reason_maps_to_content_filter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '']], 'role' => 'model'],
                    'finishReason' => 'RECITATION',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 0],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::ContentFilter, $response->steps->last()->finishReason);
    }

    // ─── Streaming ──────────────────────────────────────────────────

    public function test_streaming_thinking_parts_use_thought_boolean(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'thinking...', 'thought' => true]]),
                    $this->geminiChunk([['text' => 'Answer']]),
                    $this->geminiChunkWithUsage([], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $reasoningStart = array_filter($events, fn ($e) => $e instanceof ReasoningStart);
        $reasoningDelta = array_filter($events, fn ($e) => $e instanceof ReasoningDelta);
        $reasoningEnd = array_filter($events, fn ($e) => $e instanceof ReasoningEnd);

        $this->assertNotEmpty($reasoningStart);
        $this->assertNotEmpty($reasoningDelta);
        $this->assertNotEmpty($reasoningEnd);

        $delta = array_values($reasoningDelta)[0];
        $this->assertSame('thinking...', $delta->delta);
    }

    public function test_streaming_uses_sse_endpoint_with_alt_parameter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunkWithUsage([['text' => 'Hello']], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $this->collectStreamEvents();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'streamGenerateContent?alt=sse');
        });
    }

    public function test_streaming_model_version_in_stream_start(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunkWithUsage([['text' => 'Hi']], 10, 5, modelVersion: 'gemini-3-flash-preview'),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamStart = array_values(array_filter($events, fn ($e) => $e instanceof StreamStart))[0];
        $this->assertSame('gemini-3-flash-preview', $streamStart->model);
    }

    // ─── Error Response ─────────────────────────────────────────────

    public function test_error_in_response_data_throws_ai_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => 'Request payload size exceeds the limit.',
                ],
            ]),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error');

        (new AssistantAgent)->prompt('Hi', provider: 'gemini');
    }

    // ─── Multi-turn Conversation ────────────────────────────────────

    public function test_model_role_is_used_for_assistant_messages_in_follow_up(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();
        $followUpContents = $recorded[1][0]->data()['contents'];

        $hasModelRole = false;

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                $hasModelRole = true;
            }
        }

        $this->assertTrue($hasModelRole, 'Follow-up should use "model" role for assistant messages');
    }

    public function test_tool_results_use_user_role(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();
        $followUpContents = $recorded[1][0]->data()['contents'];

        $hasFunctionResponseInUserRole = false;

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'user') {
                foreach ($content['parts'] ?? [] as $part) {
                    if (isset($part['functionResponse'])) {
                        $hasFunctionResponseInUserRole = true;
                    }
                }
            }
        }

        $this->assertTrue($hasFunctionResponseInUserRole, 'Function responses should be in user role');
    }

    // ─── Helpers ────────────────────────────────────────────────────

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $events = [];

        foreach ($agent->stream('Hello', provider: 'gemini') as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        return implode("\n\n", array_map(
            fn ($e) => 'data: '.json_encode($e),
            $events,
        ))."\n\n";
    }

    protected function geminiChunk(array $parts, ?string $modelVersion = null): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => $parts, 'role' => 'model'],
            ]],
            'modelVersion' => $modelVersion ?? 'gemini-3-flash-preview',
        ];
    }

    protected function geminiChunkWithUsage(array $parts, int $promptTokens, int $candidatesTokens, int $cachedTokens = 0, ?string $modelVersion = null): array
    {
        $chunk = $this->geminiChunk($parts, $modelVersion);
        $chunk['usageMetadata'] = array_filter([
            'promptTokenCount' => $promptTokens,
            'candidatesTokenCount' => $candidatesTokens,
            'totalTokenCount' => $promptTokens + $candidatesTokens,
            'cachedContentTokenCount' => $cachedTokens ?: null,
        ]);

        return $chunk;
    }
}
