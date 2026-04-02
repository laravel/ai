<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

class MessageMappingTest extends AnthropicTestCase
{
    public function test_user_message_maps_to_anthropic_format(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];
            $userMessage = $messages[0];

            return $userMessage['role'] === 'user'
                && $userMessage['content'][0]['type'] === 'text'
                && $userMessage['content'][0]['text'] === 'What is Laravel?';
        });
    }

    public function test_tool_result_follow_up_maps_assistant_and_tool_result_messages(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpMessages = $recorded[1][0]->data()['messages'];

        $assistantMsg = null;
        $toolResultMsg = null;

        foreach ($followUpMessages as $msg) {
            if ($msg['role'] === 'assistant') {
                foreach ($msg['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $assistantMsg = $msg;
                    }
                }
            }

            if ($msg['role'] === 'user') {
                foreach ($msg['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $toolResultMsg = $msg;
                    }
                }
            }
        }

        $this->assertNotNull($assistantMsg, 'Follow-up should include assistant message');
        $this->assertNotNull($toolResultMsg, 'Follow-up should include tool result message');

        $toolUseBlock = collect($assistantMsg['content'])->firstWhere('type', 'tool_use');
        $this->assertSame('FixedNumberGenerator', $toolUseBlock['name']);
        $this->assertArrayHasKey('input', $toolUseBlock);

        $toolResultBlock = collect($toolResultMsg['content'])->firstWhere('type', 'tool_result');
        $this->assertSame($toolUseBlock['id'], $toolResultBlock['tool_use_id']);
        $this->assertNotEmpty($toolResultBlock['content']);
    }

    public function test_base64_pdf_document_maps_to_document_content_block(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];
            $docBlock = $content[0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'base64'
                && $docBlock['source']['media_type'] === 'application/pdf'
                && $docBlock['source']['data'] === base64_encode('fake-pdf-content');
        });
    }

    public function test_uploaded_pdf_file_maps_to_document_content_block(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this file?',
            attachments: [$file],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];
            $docBlock = $content[0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'base64'
                && $docBlock['source']['media_type'] === 'application/pdf';
        });
    }

    public function test_system_instructions_are_not_in_messages_array(): void
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

            foreach ($body['messages'] as $message) {
                if ($message['role'] === 'system') {
                    return false;
                }
            }

            return isset($body['system']) && is_string($body['system']);
        });
    }
}
