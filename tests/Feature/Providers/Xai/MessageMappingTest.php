<?php

namespace Tests\Feature\Providers\Xai;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

class MessageMappingTest extends XaiTestCase
{
    public function test_user_message_maps_to_responses_api_format(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('What is Laravel?', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            return $userMsg !== null
                && collect($userMsg['content'])->contains(
                    fn ($c) => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'What is Laravel?'
                );
        });
    }

    public function test_tool_call_follow_up_uses_previous_response_id(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'xai');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);
        $this->assertNotEmpty($followUpBody['previous_response_id']);

        $hasToolOutput = collect($followUpBody['input'])->contains(
            fn ($item) => ($item['type'] ?? '') === 'function_call_output'
        );

        $this->assertTrue($hasToolOutput, 'Follow-up should include function_call_output');
    }

    public function test_remote_image_attachment_maps_to_input_image(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new RemoteImage('https://example.com/image.png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && $imageBlock['image_url'] === 'https://example.com/image.png';
        });
    }

    public function test_base64_image_attachment_maps_to_data_uri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && str_starts_with($imageBlock['image_url'], 'data:image/png;base64,');
        });
    }

    public function test_remote_document_maps_to_input_file(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = new RemoteDocument('https://example.com/report.pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $fileBlock = collect($userMsg['content'])->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && $fileBlock['file_url'] === 'https://example.com/report.pdf';
        });
    }

    public function test_system_instructions_are_in_input_array(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }
}
