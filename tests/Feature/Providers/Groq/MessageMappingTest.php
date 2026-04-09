<?php

namespace Tests\Feature\Providers\Groq;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class MessageMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.groq' => [
            ...config('ai.providers.groq'),
            'key' => 'test-key',
        ]]);
    }

    public function test_user_message_maps_to_groq_format(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->fakeGroqResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'groq',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $messages = $body['messages'];
            $userMessage = collect($messages)->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'] === 'What is Laravel?';
        });
    }

    public function test_tool_result_follow_up_maps_assistant_and_tool_result_messages(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                $this->fakeGroqToolCallResponse(),
                $this->fakeGroqResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'groq',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);
        $followUpMessages = $followUpBody['messages'];

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpMessages as $msg) {
            if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }

            if ($msg['role'] === 'tool') {
                $hasToolResult = true;
            }
        }

        $this->assertTrue($hasAssistantWithToolCalls, 'Follow-up should include assistant message with tool_calls');
        $this->assertTrue($hasToolResult, 'Follow-up should include tool result message');

        $assistantMsg = collect($followUpMessages)->last(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls']));
        $toolMsg = collect($followUpMessages)->last(fn ($m) => $m['role'] === 'tool');

        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
        $this->assertSame($assistantMsg['tool_calls'][0]['id'], $toolMsg['tool_call_id']);
        $this->assertNotEmpty($toolMsg['content']);
    }

    public function test_image_attachment_maps_to_image_url_content_block(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->fakeGroqResponse('I see an image'),
        ]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'groq',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_contains($imageBlock['image_url']['url'], 'image/png')
                && str_contains($imageBlock['image_url']['url'], base64_encode('fake-image-data'));
        });
    }

    public function test_document_attachments_throw_exception(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->fakeGroqResponse(),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'groq',
        );
    }

    protected function fakeGroqResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]);
    }

    protected function fakeGroqToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => '{}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }
}
