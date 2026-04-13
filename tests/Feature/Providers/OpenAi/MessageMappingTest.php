<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to openai format', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse(),
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
});

test('tool result follow up uses previous response id', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->toBe('resp_tool_123');

    $hasFunctionCallOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasFunctionCallOutput = true;
            expect($item['call_id'])->toBe('call_123')
                ->and($item['output'])->not->toBeEmpty();
        }
    }

    expect($hasFunctionCallOutput)->toBeTrue();
});

test('base64 pdf document maps to input file', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
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
});

test('uploaded pdf file maps to input file', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
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
});

test('system instructions are in input array as system role', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse(),
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
});
