<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to responses api format', function () {
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
});

test('tool call follow up uses previous response id', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'xai');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->not->toBeEmpty();

    $hasToolOutput = collect($followUpBody['input'])->contains(
        fn ($item) => ($item['type'] ?? '') === 'function_call_output'
    );

    expect($hasToolOutput)->toBeTrue('Follow-up should include function_call_output');
});

test('remote image attachment maps to input image', function () {
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
});

test('base64 image attachment maps to data uri', function () {
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
});

test('remote document maps to input file', function () {
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
});

test('system instructions are in input array', function () {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});
