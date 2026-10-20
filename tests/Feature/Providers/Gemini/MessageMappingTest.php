<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;

use function Laravel\Ai\agent;

function geminiRequestBody(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_contains((string) $request->url(), 'generativelanguage.googleapis.com'))
        ->data();
}

function geminiInputBlock(string $type): ?array
{
    foreach (geminiRequestBody()['input'] as $step) {
        foreach ($step['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
        }
    }

    return null;
}

test('user message maps to a user input step', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'gemini',
    );

    expect(geminiRequestBody()['input'][0])->toMatchArray([
        'type' => 'user_input',
        'content' => [['type' => 'text', 'text' => 'What is Laravel?']],
    ]);
});

test('tool result follow up maps a function call and function result step', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 72019'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            $toolResults = collect([
                'custom_key' => new ToolResult('call_123', 'FixedNumberGenerator', [], 123),
            ]);

            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', collect([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
                new ToolResultMessage($toolResults),
            ];
        }
    };

    $agent->prompt('Follow up', provider: 'gemini');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    $input = $recorded[0][0]->data()['input'];

    $functionCall = collect($input)->firstWhere('type', 'function_call');
    $functionResult = collect($input)->firstWhere('type', 'function_result');

    expect($functionCall)->not->toBeNull('Follow-up should include a function_call step')
        ->and($functionCall['name'])->toBe('FixedNumberGenerator')
        ->and($functionCall['id'])->toBe('call_123')
        ->and($functionResult)->not->toBeNull('Follow-up should include a function_result step')
        ->and($functionResult['call_id'])->toBe('call_123')
        ->and($functionResult['name'])->toBe('FixedNumberGenerator')
        ->and(array_is_list($functionResult['result']))->toBeTrue('Tool results must be a sequential array')
        ->and($functionResult['result'][0]['type'])->toBe('text');
});

test('prior assistant tool call sends its arguments as an object', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', new Collection([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
            ];
        }
    };

    $agent->prompt('And again', provider: 'gemini');

    $body = Http::recorded()[0][0]->body();

    expect($body)->toContain('"arguments":{}');
});

test('prior assistant steps are replayed to gemini verbatim', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
    ]);

    $replayed = [
        ['type' => 'thought', 'summary' => [['type' => 'text', 'text' => 'Thinking.']], 'signature' => 'sig_123'],
        ['type' => 'function_call', 'id' => 'call_123', 'name' => 'FixedNumberGenerator', 'arguments' => []],
    ];

    $agent = new class($replayed) implements Agent, Conversational
    {
        use Promptable;

        public function __construct(protected array $replayed) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', new Collection([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ]), replayBlocks: $this->replayed),
            ];
        }
    };

    $agent->prompt('And again', provider: 'gemini');

    [$request] = Http::recorded()[0];

    expect($request->data()['input'][1])->toBe($replayed[0])
        ->and($request->body())->toContain('{"type":"function_call","id":"call_123","name":"FixedNumberGenerator","arguments":{}}');
});

test('a rebuilt assistant turn with text opens with its thought step', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
    ]);

    agent('You are a helpful assistant.', [
        new Message(role: 'user', content: 'Generate a number'),
        new AssistantMessage('Let me generate one.', new Collection([
            new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123', thoughtSignature: 'sig_123'),
        ])),
        new ToolResultMessage(new Collection([
            new ToolResult('call_123', 'FixedNumberGenerator', [], 123, 'call_123'),
        ])),
    ])->prompt('And again', provider: 'gemini');

    $input = geminiRequestBody()['input'];

    expect(array_column($input, 'type'))->toBe([
        'user_input', 'thought', 'model_output', 'function_call', 'function_result', 'user_input',
    ])->and($input[1])->toBe(['type' => 'thought', 'signature' => 'sig_123']);
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('image'))->toMatchArray(['mime_type' => 'image/png']);
});

test('an attachment only message sends no text block', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        '',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'gemini',
    );

    expect(geminiRequestBody()['input'][0]['content'])->toHaveCount(1)
        ->and(geminiRequestBody()['input'][0]['content'][0]['type'])->toBe('image');
});

test('provider audio maps to an audio URI block without downloading the file', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*interactions*' => $this->fakeTextResponse('I hear audio'),
    ]);

    $uri = 'https://generativelanguage.googleapis.com/v1beta/files/audio-123';

    agent('You are helpful.')->prompt(
        'What is in this audio?',
        attachments: [Audio::fromId($uri, 'audio/mpeg')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('audio'))->toBe([
        'type' => 'audio',
        'mime_type' => 'audio/mpeg',
        'uri' => $uri,
    ])->and(Http::recorded())->toHaveCount(1);
});

test('provider video maps to a video URI block without downloading the file', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*interactions*' => $this->fakeTextResponse('I see a video'),
    ]);

    $uri = 'https://generativelanguage.googleapis.com/v1beta/files/video-123';

    agent('You are helpful.')->prompt(
        'What is in this video?',
        attachments: [Video::fromId($uri, 'video/mp4')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('video'))->toBe([
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'uri' => $uri,
    ])->and(Http::recorded())->toHaveCount(1);
});

test('provider audio and video omit MIME when it is unknown', function (): void {
    Http::fake([
        '*interactions*' => $this->fakeTextResponse('I see the media'),
    ]);

    agent('You are helpful.')->prompt(
        'Describe these files.',
        attachments: [
            Audio::fromId('https://generativelanguage.googleapis.com/v1beta/files/audio-123'),
            Video::fromId('https://generativelanguage.googleapis.com/v1beta/files/video-123'),
        ],
        provider: 'gemini',
    );

    expect(geminiInputBlock('audio'))->toBe([
        'type' => 'audio',
        'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/audio-123',
    ])->and(geminiInputBlock('video'))->toBe([
        'type' => 'video',
        'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/video-123',
    ]);
});

test('provider image and document URI mappings remain unchanged', function (): void {
    Http::fake([
        '*interactions*' => $this->fakeTextResponse('I see the files'),
    ]);

    agent('You are helpful.')->prompt(
        'Describe these files.',
        attachments: [Image::fromId('files/image-123'), Document::fromId('files/document-123')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('image'))->toBe([
        'type' => 'image',
        'uri' => 'files/image-123',
    ])->and(geminiInputBlock('document'))->toBe([
        'type' => 'document',
        'uri' => 'files/document-123',
    ]);
});

test('base64 pdf document maps to a document block', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'gemini',
    );

    expect(geminiInputBlock('document'))->toMatchArray([
        'mime_type' => 'application/pdf',
        'data' => base64_encode('fake-pdf-content'),
    ]);
});

test('base64 video attachment maps to a video block', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a video'),
    ]);

    $video = new Base64Video(base64_encode('fake-video-content'), 'video/mp4');

    agent('You are helpful.')->prompt(
        'What is in this video?',
        attachments: [$video],
        provider: 'gemini',
    );

    expect(geminiInputBlock('video'))->toMatchArray([
        'mime_type' => 'video/mp4',
        'data' => base64_encode('fake-video-content'),
    ]);
});

test('remote image url is fetched and mapped to an image block', function (): void {
    Http::fake([
        'example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/png']),
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new RemoteImage('https://example.com/photo.png', 'image/png')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('image'))->toMatchArray([
        'mime_type' => 'image/png',
        'data' => base64_encode('fake-image-bytes'),
    ]);
});

test('remote pdf url is fetched and mapped to a document block', function (): void {
    Http::fake([
        'example.com/*' => Http::response('fake-pdf-bytes', 200, ['Content-Type' => 'application/pdf']),
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [new RemoteDocument('https://example.com/report.pdf', 'application/pdf')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('document'))->toMatchArray([
        'mime_type' => 'application/pdf',
        'data' => base64_encode('fake-pdf-bytes'),
    ]);
});

test('remote audio url is fetched and mapped to an audio block', function (): void {
    Http::fake([
        'example.com/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mp3']),
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I hear audio'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this audio?',
        attachments: [new RemoteAudio('https://example.com/clip.mp3', 'audio/mp3')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('audio'))->toMatchArray([
        'mime_type' => 'audio/mp3',
        'data' => base64_encode('fake-audio-bytes'),
    ]);
});

test('stored text document sends real mime type', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    Storage::fake('docs');
    Storage::disk('docs')->put('notes.txt', 'stored text contents');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [Document::fromStorage('notes.txt', 'docs')],
        provider: 'gemini',
    );

    expect(geminiInputBlock('document'))->toMatchArray([
        'mime_type' => 'text/plain',
        'data' => base64_encode('stored text contents'),
    ]);
});

test('system instructions are not part of the input steps', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    expect(array_column(geminiRequestBody()['input'], 'type'))->not->toContain('system')
        ->and(geminiRequestBody())->toHaveKey('system_instruction');
});
