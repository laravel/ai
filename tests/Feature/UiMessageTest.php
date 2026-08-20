<?php

use Illuminate\Http\Request;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
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

    test('non text parts are ignored', function () {
        $message = Vercel::messageFrom([
            'id' => 'm1',
            'role' => 'assistant',
            'parts' => [
                ['type' => 'step-start'],
                ['type' => 'reasoning', 'text' => 'thinking...'],
                ['type' => 'tool-getWeather', 'toolCallId' => 'call_1', 'state' => 'output-available', 'input' => [], 'output' => 'Sunny'],
                ['type' => 'text', 'text' => 'It is sunny.'],
            ],
        ]);

        expect($message->content)->toBe('It is sunny.')
            ->and($message->toolCalls)->toBeEmpty();
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
    test('messages become text-only UI message arrays', function () {
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
});
