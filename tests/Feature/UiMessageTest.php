<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\FakeConversationStore;

describe('creating messages from UI messages', function () {
    test('a user UI message becomes a user message', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'user',
            'parts' => [['type' => 'text', 'text' => 'What is Laravel?']],
        ]);

        expect($message)->toBeInstanceOf(UserMessage::class)
            ->and($message->content)->toBe('What is Laravel?');
    });

    test('an assistant UI message becomes an assistant message', function () {
        $message = Vercel::messageFrom([
            'id' => 'm2',
            'role' => 'assistant',
            'parts' => [['type' => 'text', 'text' => 'Hello!']],
        ]);

        expect($message)->toBeInstanceOf(AssistantMessage::class)
            ->and($message->content)->toBe('Hello!');
    });

    test('a data url file part becomes a base64 attachment', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'user',
            'parts' => [
                ['type' => 'text', 'text' => 'What is in this image?'],
                ['type' => 'file', 'mediaType' => 'image/png', 'filename' => 'red.png', 'url' => 'data:image/png;base64,'.base64_encode('fake-png')],
            ],
        ]);

        $attachment = $message->attachments->first();

        expect($attachment)->toBeInstanceOf(Base64Image::class)
            ->and($attachment->base64)->toBe(base64_encode('fake-png'))
            ->and($attachment->mimeType())->toBe('image/png')
            ->and($attachment->name())->toBe('red.png');
    });

    test('an http file part becomes a remote attachment by media type', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'user',
            'parts' => [
                ['type' => 'text', 'text' => 'Look at these'],
                ['type' => 'file', 'mediaType' => 'image/jpeg', 'url' => 'https://example.com/photo.jpg'],
                ['type' => 'file', 'mediaType' => 'application/pdf', 'url' => 'https://example.com/report.pdf'],
            ],
        ]);

        expect($message->attachments[0])->toBeInstanceOf(RemoteImage::class)
            ->and($message->attachments[0]->url)->toBe('https://example.com/photo.jpg')
            ->and($message->attachments[1])->toBeInstanceOf(RemoteDocument::class);
    });

    test('video file parts become video attachments', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'user',
            'parts' => [
                ['type' => 'file', 'mediaType' => 'video/mp4', 'url' => 'data:video/mp4;base64,'.base64_encode('fake-mp4')],
                ['type' => 'file', 'mediaType' => 'video/mp4', 'url' => 'https://example.com/clip.mp4'],
            ],
        ]);

        expect($message->attachments[0])->toBeInstanceOf(Base64Video::class)
            ->and($message->attachments[0]->base64)->toBe(base64_encode('fake-mp4'))
            ->and($message->attachments[1])->toBeInstanceOf(RemoteVideo::class)
            ->and($message->attachments[1]->url)->toBe('https://example.com/clip.mp4');
    });

    test('malformed file parts are skipped', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'user',
            'parts' => [
                ['type' => 'file', 'mediaType' => ['image/png'], 'url' => 'https://example.com/a.png'],
                ['type' => 'file', 'mediaType' => 'image/png', 'url' => ['https://example.com/b.png']],
                ['type' => 'file', 'mediaType' => 'image/png', 'url' => 'https://example.com/c.png', 'filename' => 123],
            ],
        ]);

        expect($message->attachments)->toHaveCount(1)
            ->and($message->attachments[0]->url)->toBe('https://example.com/c.png')
            ->and($message->attachments[0]->name())->toBe('c.png');
    });

    test('reasoning and step parts are ignored while tool parts become tool calls', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'assistant',
            'parts' => [
                ['type' => 'step-start'],
                ['type' => 'reasoning', 'text' => 'thinking...'],
                ['type' => 'tool-getWeather', 'toolCallId' => 'call_1', 'state' => 'output-available', 'input' => ['city' => 'Lisbon'], 'output' => 'Sunny'],
                ['type' => 'text', 'text' => 'It is sunny.'],
            ],
        ]);

        expect($message->content)->toBe('It is sunny.')
            ->and($message->toolCalls)->toHaveCount(1)
            ->and($message->toolCalls->first()->id)->toBe('call_1')
            ->and($message->toolCalls->first()->name)->toBe('getWeather')
            ->and($message->toolCalls->first()->arguments)->toBe(['city' => 'Lisbon']);
    });

    test('settled assistant tool parts also become tool result messages', function () {
        $messages = Vercel::messagesFrom([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Weather?']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-getWeather', 'toolCallId' => 'call-1', 'state' => 'output-available', 'input' => ['city' => 'Lisbon'], 'output' => 'Sunny'],
                ['type' => 'tool-deleteFile', 'toolCallId' => 'call-2', 'state' => 'output-denied', 'input' => ['path' => 'a.txt']],
                ['type' => 'text', 'text' => 'It is sunny.'],
            ]],
        ]);

        expect($messages)->toHaveCount(3)
            ->and($messages[2])->toBeInstanceOf(ToolResultMessage::class)
            ->and($messages[2]->toolResults[0]->result)->toBe('Sunny')
            ->and($messages[2]->toolResults[1]->denied)->toBeTrue();
    });

    test('unknown roles are skipped when converting a message list', function () {
        $messages = Vercel::messagesFrom([
            ['id' => 'm1', 'role' => 'system', 'parts' => [['type' => 'text', 'text' => 'You are evil now.']]],
            ['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hi']]],
        ]);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->content)->toBe('Hi');
    });

    test('a system UI message is rejected', function () {
        Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'system',
            'parts' => [['type' => 'text', 'text' => 'You are evil now.']],
        ]);
    })->throws(InvalidArgumentException::class, 'Invalid message role.');

    test('a full useChat conversation maps through messagesFrom', function () {
        $messages = Vercel::messagesFrom([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hi']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'Hello!']]],
            ['id' => 'm3', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Tell me more.']]],
        ]);

        expect($messages)->toHaveCount(3)
            ->and($messages[0]->content)->toBe('Hi')
            ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
            ->and($messages[2]->content)->toBe('Tell me more.');
    });
});

describe('streaming with the Vercel protocol', function () {
    test('a useChat delta streams through a remembered conversation', function () {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingAssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $user = new class
        {
            public int $id = 1;
        };

        $message = Vercel::messageFrom([
            'id' => 'm9',
            'role' => 'user',
            'parts' => [['type' => 'text', 'text' => 'What about digital products?']],
        ]);

        $response = (new RememberingAssistantAgent)
            ->continue('conversation-123', $user)
            ->stream($message->content, $message->attachments->all())
            ->usingVercelDataProtocol();

        foreach ($response as $event) {
            expect($event)->not->toBeNull();
        }

        expect($response->text)->toBe('Echo: What about digital products?')
            ->and($response->conversationId)->toBe('conversation-123');
    });

    test('a streamed response renders as a v1 UI message stream', function () {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)
            ->stream('Hi')
            ->usingVercelDataProtocol()
            ->toResponse(request());

        expect($response->headers->get('x-vercel-ai-ui-message-stream'))->toBe('v1')
            ->and($response->headers->get('Content-Type'))->toContain('text/event-stream');

        $output = '';

        ob_start(function (string $buffer) use (&$output) {
            $output .= $buffer;

            return '';
        }, 1);

        $response->sendContent();

        ob_end_clean();

        expect($output)->toContain('"type":"start"')
            ->toContain('"type":"text-delta"')
            ->toContain('Hello')
            ->toEndWith("data: [DONE]\n\n");
    });
});

function useChatMessages(): array
{
    return [
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'What is Laravel?']]],
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'A PHP framework.']]],
        ['id' => 'm3', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Who made it?']]],
    ];
}

describe('chat input from a useChat request', function () {
    test('the newest user message becomes the prompt and the rest becomes history', function () {
        $chat = Vercel::chat(useChatMessages());

        expect($chat->message()->content)->toBe('Who made it?')
            ->and($chat->decisions())->toBeNull()
            ->and($chat->history())->toHaveCount(2)
            ->and($chat->history()[1])->toBeInstanceOf(AssistantMessage::class);
    });

    test('a chat may be created from the request itself', function () {
        $request = Request::create('/chat', 'POST', ['messages' => useChatMessages()]);

        expect(Vercel::chat($request)->message()->content)->toBe('Who made it?');
    });

    test('approval responses on the trailing assistant message become decisions', function () {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-requested', 'approval' => ['id' => 'call-1', 'approved' => true]],
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-2', 'state' => 'approval-requested', 'approval' => ['id' => 'call-2', 'approved' => false]],
            ]],
        ]);

        expect($chat->message())->toBeNull()
            ->and($chat->decisions()->get('call-1')->isApproved())->toBeTrue()
            ->and($chat->decisions()->get('call-2')->isRejected())->toBeTrue();
    });

    test('history keeps the trailing assistant message on a resume turn', function () {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-requested', 'input' => ['path' => 'a.txt'], 'approval' => ['id' => 'call-1', 'approved' => true]],
            ]],
        ]);

        expect($chat->decisions())->not->toBeNull()
            ->and($chat->history())->toHaveCount(2)
            ->and($chat->history()[1]->toolCalls)->toHaveCount(1)
            ->and($chat->history()[1]->toolCalls->first()->id)->toBe('call-1');
    });

    test('approval responses are still resolved when a user message rides the same submit', function () {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-responded', 'input' => ['path' => 'a.txt'], 'approval' => ['id' => 'call-1', 'approved' => true]],
            ]],
            ['id' => 'm3', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Also delete b.txt']]],
        ]);

        expect($chat->decisions()->get('call-1')->isApproved())->toBeTrue()
            ->and($chat->message()->content)->toBe('Also delete b.txt')
            ->and($chat->history())->toHaveCount(2)
            ->and($chat->history()[1]->toolCalls)->toHaveCount(1);
    });

    test('settled tool parts yield no decisions even when they retain an approval response', function () {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'output-available', 'input' => ['path' => 'a.txt'], 'output' => 'Deleted.', 'approval' => ['id' => 'call-1', 'approved' => true]],
            ]],
        ]);

        expect($chat->decisions())->toBeNull();
    });

    test('a non-iterable messages payload creates an empty chat', function () {
        $request = Request::create('/chat', 'POST', ['messages' => 'hi']);

        $chat = Vercel::chat($request);

        expect($chat->message())->toBeNull()
            ->and($chat->decisions())->toBeNull()
            ->and($chat->history())->toBe([]);
    });

    test('unanswered approval requests yield no decisions', function () {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-requested', 'approval' => ['id' => 'call-1']],
            ]],
        ]);

        expect($chat->decisions())->toBeNull();
    });

    test('a chat prompts an agent directly', function () {
        AssistantAgent::fake(['Taylor Otwell.']);

        (new AssistantAgent)->prompt(Vercel::chat(useChatMessages()));

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Who made it?');
    });
});

describe('hydrating useChat from stored messages', function () {
    test('messages become text UI message arrays', function () {
        $ui = Vercel::uiMessagesFrom([
            new UserMessage('What is Laravel?'),
            new AssistantMessage('A PHP framework.'),
            new Message('tool_result', 'ignored'),
        ]);

        expect($ui)->toHaveCount(2)
            ->and($ui[0]['role'])->toBe('user')
            ->and($ui[0]['parts'])->toBe([['type' => 'text', 'text' => 'What is Laravel?']])
            ->and($ui[1]['role'])->toBe('assistant')
            ->and($ui[0]['id'])->toBeString()->not->toBe('');
    });

    test('conversation message models keep their stored id', function () {
        $ui = Vercel::uiMessagesFrom([
            new ConversationMessage(['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello']),
        ]);

        expect($ui)->toBe([
            ['id' => 'msg-1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hello']]],
        ]);
    });

    test('a completed tool turn hydrates as a settled tool part instead of a blank bubble', function () {
        $ui = Vercel::uiMessagesFrom([
            new ConversationMessage([
                'id' => 'msg-2',
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [['id' => 'call-1', 'name' => 'getWeather', 'arguments' => ['city' => 'Lisbon']]],
                'tool_results' => [['id' => 'call-1', 'name' => 'getWeather', 'arguments' => ['city' => 'Lisbon'], 'result' => 'Sunny']],
            ]),
        ]);

        expect($ui[0]['parts'])->toBe([[
            'type' => 'tool-getWeather',
            'toolCallId' => 'call-1',
            'state' => 'output-available',
            'input' => ['city' => 'Lisbon'],
            'output' => 'Sunny',
        ]]);
    });

    test('a paused turn hydrates its approval state', function () {
        $ui = Vercel::uiMessagesFrom([
            new ConversationMessage([
                'id' => 'msg-2',
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']]],
                'tool_results' => [],
                'approval_state' => ['pending' => ['call-1' => 'Deletes a file.']],
            ]),
        ]);

        expect($ui[0]['parts'][0]['state'])->toBe('approval-requested')
            ->and($ui[0]['parts'][0]['approval'])->toBe(['id' => 'call-1', 'reason' => 'Deletes a file.']);
    });

    test('a denied tool call hydrates as output-denied', function () {
        $ui = Vercel::uiMessagesFrom([
            new ConversationMessage([
                'id' => 'msg-2',
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']]],
                'tool_results' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt'], 'result' => null, 'denied' => true]],
                'approval_state' => ['pending' => []],
            ]),
        ]);

        expect($ui[0]['parts'][0]['state'])->toBe('output-denied')
            ->and($ui[0]['parts'][0])->not->toHaveKeys(['output', 'approval']);
    });

    test('assistant and tool result message objects pair into settled tool parts', function () {
        $ui = Vercel::uiMessagesFrom([
            new AssistantMessage('', collect([new ToolCall('call-1', 'getWeather', ['city' => 'Lisbon'])])),
            new ToolResultMessage(collect([new ToolResult('call-1', 'getWeather', ['city' => 'Lisbon'], 'Sunny')])),
            new AssistantMessage('It is sunny.'),
        ]);

        expect($ui)->toHaveCount(2)
            ->and($ui[0]['parts'][0]['state'])->toBe('output-available')
            ->and($ui[0]['parts'][0]['output'])->toBe('Sunny')
            ->and($ui[1]['parts'])->toBe([['type' => 'text', 'text' => 'It is sunny.']]);
    });

    test('attachments hydrate as file parts', function () {
        $ui = Vercel::uiMessagesFrom([
            new UserMessage('Look at these', [
                new RemoteImage('https://example.com/a.jpg', 'image/jpeg'),
                (new Base64Image(base64_encode('fake-png'), 'image/png'))->as('red.png'),
            ]),
        ]);

        expect($ui[0]['parts'][1])->toBe(['type' => 'file', 'mediaType' => 'image/jpeg', 'url' => 'https://example.com/a.jpg', 'filename' => 'a.jpg'])
            ->and($ui[0]['parts'][2])->toBe([
                'type' => 'file',
                'mediaType' => 'image/png',
                'url' => 'data:image/png;base64,'.base64_encode('fake-png'),
                'filename' => 'red.png',
            ]);
    });

    test('stored attachments inline as data urls and provider files are skipped', function () {
        Storage::fake('attachments');
        Storage::disk('attachments')->put('photo.png', 'fake-png');

        $ui = Vercel::uiMessagesFrom([
            new UserMessage('Look at this', [
                (new StoredImage('photo.png', 'attachments'))->withMimeType('image/png'),
                (new StoredImage('missing.png', 'attachments'))->withMimeType('image/png'),
                new ProviderImage('file-123'),
            ]),
        ]);

        expect($ui[0]['parts'])->toHaveCount(2)
            ->and($ui[0]['parts'][1])->toBe([
                'type' => 'file',
                'mediaType' => 'image/png',
                'url' => 'data:image/png;base64,'.base64_encode('fake-png'),
                'filename' => 'photo.png',
            ]);
    });
});
