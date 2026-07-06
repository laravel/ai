<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('user message maps to azure format', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'azure',
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
        'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
            fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'azure',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->toBe('resp_azure_tool_123');

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

test('azure store false enables stateless inline conversation', function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'store' => false,
    ]]);

    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
            fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'azure');

    $recorded = Http::recorded();
    $initialBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($initialBody['store'] ?? null)->toBeFalse()
        ->and($followUpBody)->not->toHaveKey('previous_response_id')
        ->and($followUpBody['store'] ?? null)->toBeFalse();
});

test('image attachment maps to input_image content block', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'azure',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $content = $userMessage['content'];

        $imageBlock = collect($content)->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && str_contains($imageBlock['image_url'], 'image/png')
            && str_contains($imageBlock['image_url'], base64_encode('fake-image-data'));
    });
});

test('attachment provider options closure receives the azure provider', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
    ]);

    $image = (new Base64Image(base64_encode('fake-image-data'), 'image/png'))
        ->withProviderOptions(fn (Lab $provider) => match ($provider) {
            Lab::Azure => ['detail' => 'low'],
            default => ['detail' => 'high'],
        });

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'azure',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && ($imageBlock['detail'] ?? null) === 'low';
    });
});

test('document attachment maps to input_file content block', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'azure',
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
