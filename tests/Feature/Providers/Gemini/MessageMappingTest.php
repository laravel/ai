<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

class MessageMappingTest extends GeminiTestCase
{
    public function test_user_message_maps_to_gemini_format(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'];
            $userMessage = $contents[0];

            return $userMessage['role'] === 'user'
                && $userMessage['parts'][0]['text'] === 'What is Laravel?';
        });
    }

    public function test_tool_result_follow_up_maps_model_and_function_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $hasModelWithFunctionCall = false;
        $hasFunctionResponse = false;

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] ?? [] as $part) {
                    if (isset($part['functionCall'])) {
                        $hasModelWithFunctionCall = true;
                    }
                }
            }

            if ($content['role'] === 'user') {
                foreach ($content['parts'] ?? [] as $part) {
                    if (isset($part['functionResponse'])) {
                        $hasFunctionResponse = true;
                    }
                }
            }
        }

        $this->assertTrue($hasModelWithFunctionCall, 'Follow-up should include model message with functionCall');
        $this->assertTrue($hasFunctionResponse, 'Follow-up should include user message with functionResponse');
    }

    public function test_base64_pdf_document_maps_to_inline_data(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'];

            foreach ($parts as $part) {
                if (isset($part['inlineData'])) {
                    return $part['inlineData']['mimeType'] === 'application/pdf'
                        && $part['inlineData']['data'] === base64_encode('fake-pdf-content');
                }
            }

            return false;
        });
    }

    public function test_system_instructions_are_not_in_contents_array(): void
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

            foreach ($body['contents'] as $content) {
                if ($content['role'] === 'system') {
                    return false;
                }
            }

            return isset($body['system_instruction']);
        });
    }
}
