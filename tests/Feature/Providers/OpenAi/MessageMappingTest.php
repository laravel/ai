<?php

namespace Tests\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class MessageMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_user_message_maps_to_openai_format(): void
    {
        Http::fake([
            'api.openai.com/*' => $this->fakeOpenAiResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'openai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $input = $body['input'];
            $userMessage = collect($input)->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'][0]['type'] === 'input_text'
                && $userMessage['content'][0]['text'] === 'What is Laravel?';
        });
    }

    public function test_tool_result_follow_up_uses_previous_response_id(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                $this->fakeOpenAiToolCallResponse(),
                $this->fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'openai',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);
        $this->assertSame('resp_tool_123', $followUpBody['previous_response_id']);

        $hasFunctionCallOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasFunctionCallOutput = true;
                $this->assertSame('call_123', $item['call_id']);
                $this->assertNotEmpty($item['output']);
            }
        }

        $this->assertTrue($hasFunctionCallOutput, 'Follow-up should include function_call_output');
    }

    public function test_base64_pdf_document_maps_to_input_file(): void
    {
        Http::fake([
            'api.openai.com/*' => $this->fakeOpenAiResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $fileBlock = collect($content)->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && str_contains($fileBlock['file_data'], 'application/pdf')
                && str_contains($fileBlock['file_data'], base64_encode('fake-pdf-content'));
        });
    }

    public function test_uploaded_pdf_file_maps_to_input_file(): void
    {
        Http::fake([
            'api.openai.com/*' => $this->fakeOpenAiResponse('I see a PDF'),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this file?',
            attachments: [$file],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $fileBlock = collect($content)->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && str_contains($fileBlock['file_data'], 'application/pdf');
        });
    }

    public function test_system_instructions_are_in_input_array_as_system_role(): void
    {
        Http::fake([
            'api.openai.com/*' => $this->fakeOpenAiResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }

    protected function fakeOpenAiResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }

    protected function fakeOpenAiToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_tool_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'function_call',
                'id' => 'fc_123',
                'call_id' => 'call_123',
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ]);
    }
}
