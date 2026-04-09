<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

class MessageMappingTest extends MistralTestCase
{
    public function test_user_message_maps_to_chat_format(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('What is Laravel?', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');

            return $userMsg['content'] === 'What is Laravel?';
        });
    }

    public function test_assistant_message_with_tool_calls_maps_correctly(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'mistral');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $assistantMsg = collect($followUpBody['messages'])->firstWhere('role', 'assistant');
        $toolMsg = collect($followUpBody['messages'])->firstWhere('role', 'tool');

        $this->assertNotNull($assistantMsg, 'Follow-up should include assistant message');
        $this->assertNotNull($toolMsg, 'Follow-up should include tool result message');
        $this->assertNotEmpty($assistantMsg['tool_calls']);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
        $this->assertSame($assistantMsg['tool_calls'][0]['id'], $toolMsg['tool_call_id']);
    }

    public function test_image_attachment_maps_to_image_url(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new RemoteImage('https://example.com/image.png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && $imageBlock['image_url']['url'] === 'https://example.com/image.png';
        });
    }

    public function test_base64_image_attachment_maps_to_data_uri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with($imageBlock['image_url']['url'], 'data:image/png;base64,');
        });
    }

    public function test_remote_document_maps_to_document_url(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = new RemoteDocument('https://example.com/report.pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $docBlock = collect($content)->firstWhere('type', 'document_url');

            return $docBlock !== null
                && $docBlock['document_url'] === 'https://example.com/report.pdf';
        });
    }

    public function test_system_instructions_are_in_messages_array(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }
}
