<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\Transcript;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\FakeConversationStore;

describe('prompting with a transcript', function () {
    test('a transcript of message objects is accepted', function () {
        AssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $response = (new AssistantAgent)->prompt([
            new UserMessage('What is our refund policy?'),
            new AssistantMessage('Returns within 30 days.'),
            new UserMessage('What about digital products?'),
        ]);

        expect($response->text)->toEqual('Echo: What about digital products?');

        AssistantAgent::assertPrompted('What about digital products?');
    });

    test('a transcript of plain array shapes is accepted', function () {
        AssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $response = (new AssistantAgent)->prompt([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there.'],
            ['role' => 'user', 'content' => 'How are you?'],
        ]);

        expect($response->text)->toEqual('Echo: How are you?');
    });

    test('a transcript mixing message objects and array shapes is accepted', function () {
        AssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $response = (new AssistantAgent)->prompt([
            new UserMessage('Hello'),
            ['role' => 'assistant', 'content' => 'Hi there.'],
            new UserMessage('Follow up'),
        ]);

        expect($response->text)->toEqual('Echo: Follow up');
    });

    test('a transcript not ending in a user message is rejected', function () {
        (new AssistantAgent)->prompt([
            new UserMessage('Hello'),
            new AssistantMessage('Hi there.'),
        ]);
    })->throws(InvalidArgumentException::class, 'A transcript must end with a user message.');

    test('an empty transcript is rejected', function () {
        (new AssistantAgent)->prompt([]);
    })->throws(InvalidArgumentException::class, 'A transcript must end with a user message.');

    test('call-site attachments merge with attachments already on the trailing message', function () {
        $captured = null;

        AssistantAgent::fake([
            function (string $prompt, $attachments) use (&$captured) {
                $captured = $attachments;

                return 'Response';
            },
        ]);

        (new AssistantAgent)->prompt(
            [
                new UserMessage('Look at this', ['from-transcript.png']),
            ],
            attachments: ['from-call-site.png'],
        );

        expect($captured->all())->toEqual(['from-transcript.png', 'from-call-site.png']);
    });

    test('an explicit transcript takes precedence over Conversational::messages', function () {
        config(['ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'id' => 'chatcmpl-1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        (new ConversationalAgent)->prompt([
            new UserMessage('Explicit transcript only'),
        ], provider: 'primary');

        Http::assertSent(function ($request) {
            $contents = collect($request->data()['messages'])->pluck('content');

            return $contents->contains('Explicit transcript only')
                && $contents->doesntContain('My name is Taylor Otwell');
        });
    });

    test('a transcript cannot be prompted while a RemembersConversations conversation is active', function () {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingAssistantAgent::fake(['Fake response']);

        $user = new class
        {
            public int $id = 1;
        };

        (new RememberingAssistantAgent)->forUser($user)->prompt([
            new UserMessage('Hi'),
        ]);
    })->throws(LogicException::class);

    test('a transcript cannot be streamed while a RemembersConversations conversation is active', function () {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingAssistantAgent::fake(['Fake response']);

        $user = new class
        {
            public int $id = 1;
        };

        (new RememberingAssistantAgent)->forUser($user)->stream([
            new UserMessage('Hi'),
        ]);
    })->throws(LogicException::class);

    test('withAttachments works on a transcript-based prompt', function () {
        $prompt = new AgentPrompt(
            new AssistantAgent,
            [new UserMessage('History'), new UserMessage('Latest question')],
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
        );

        $revised = $prompt->withAttachments(['photo.png']);

        expect($revised->hasTranscript())->toBeTrue()
            ->and($revised->text())->toEqual('Latest question')
            ->and($revised->attachments->all())->toEqual(['photo.png'])
            ->and($revised->history())->toHaveCount(1);
    });

    test('revise preserves the trailing transcript message own attachments', function () {
        $prompt = new AgentPrompt(
            new AssistantAgent,
            [
                new UserMessage('History'),
                new UserMessage('Latest question', ['from-transcript.png']),
            ],
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
        );

        $revised = $prompt->append('one more thing');

        expect($revised->trailingMessage())->toBeInstanceOf(UserMessage::class)
            ->and($revised->trailingMessage()->attachments->all())->toEqual(['from-transcript.png']);

        $withCallSiteAttachment = $prompt->withAttachments(['from-call-site.png']);

        expect($withCallSiteAttachment->trailingMessage()->attachments->all())->toEqual(['from-transcript.png'])
            ->and($withCallSiteAttachment->attachments->all())->toEqual(['from-call-site.png']);
    });

    test('a transcript is allowed for a RemembersConversations agent without an active conversation', function () {
        RememberingAssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $response = (new RememberingAssistantAgent)->prompt([
            new UserMessage('Hi'),
        ]);

        expect($response->text)->toEqual('Echo: Hi');
    });
});

describe('assertions with a transcript prompt', function () {
    test('assertPrompted matches the trailing user message text', function () {
        AssistantAgent::fake(['Fake response']);

        (new AssistantAgent)->prompt([
            new UserMessage('History line'),
            new UserMessage('Latest question'),
        ]);

        AssistantAgent::assertPrompted('Latest question');
        AssistantAgent::assertNotPrompted('History line');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->hasTranscript() && $prompt->text() === 'Latest question';
        });
    });

    test('assertQueued matches the trailing user message text for a transcript', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->queue([
            new UserMessage('History line'),
            new UserMessage('Latest question'),
        ]);

        AssistantAgent::assertQueued('Latest question');
        AssistantAgent::assertNotQueued('History line');

        AssistantAgent::assertQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->hasTranscript();
        });
    });
});

describe('queue serialization with a transcript', function () {
    test('a transcript prompt survives queue serialization round-trip', function () {
        AssistantAgent::fake([
            fn (string $prompt) => "Echo: {$prompt}",
        ]);

        $job = new InvokeAgent(new AssistantAgent, [
            new UserMessage('History line'),
            new AssistantMessage('Ack.', collect([
                new ToolCall(id: 'call_1', name: 'demo', arguments: []),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(id: 'call_1', name: 'demo', arguments: [], result: 'ok'),
            ])),
            new UserMessage('Latest question'),
        ]);

        $restored = unserialize(serialize($job));

        expect($restored->prompt)->toBeArray();

        $restored->handle();

        AssistantAgent::assertPrompted('Latest question');
    });
});

describe('transcript normalization', function () {
    test('adjacent tool result messages are merged into one', function () {
        $normalized = Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('Working.', collect([
                new ToolCall(id: 'call_1', name: 'one', arguments: []),
                new ToolCall(id: 'call_2', name: 'two', arguments: []),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(id: 'call_1', name: 'one', arguments: [], result: 'a'),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(id: 'call_2', name: 'two', arguments: [], result: 'b'),
            ])),
            new UserMessage('Thanks'),
        ]);

        $toolResultMessages = array_values(array_filter(
            $normalized, fn (Message $message) => $message instanceof ToolResultMessage
        ));

        expect($toolResultMessages)->toHaveCount(1)
            ->and($toolResultMessages[0]->toolResults)->toHaveCount(2);
    });

    test('an assistant tool call with no matching result is stripped but the text is kept', function () {
        $normalized = Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('Here is some text.', collect([
                new ToolCall(id: 'call_missing', name: 'one', arguments: []),
            ])),
            new UserMessage('Continue'),
        ]);

        $assistant = $normalized[1];

        expect($assistant)->toBeInstanceOf(AssistantMessage::class)
            ->and($assistant->content)->toEqual('Here is some text.')
            ->and($assistant->toolCalls)->toHaveCount(0);
    });

    test('an assistant message that is only unmatched tool calls is dropped entirely', function () {
        $normalized = Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('', collect([
                new ToolCall(id: 'call_missing', name: 'one', arguments: []),
            ])),
            new UserMessage('Continue'),
        ]);

        expect($normalized)->toHaveCount(2)
            ->and($normalized[0])->toBeInstanceOf(UserMessage::class)
            ->and($normalized[0]->content)->toEqual('Go')
            ->and($normalized[1]->content)->toEqual('Continue');
    });

    test('an orphaned tool result message with no preceding call is dropped', function () {
        $normalized = Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('Just text, no tool calls.'),
            new ToolResultMessage(collect([
                new ToolResult(id: 'call_orphan', name: 'one', arguments: [], result: 'a'),
            ])),
            new UserMessage('Continue'),
        ]);

        $toolResultMessages = array_filter($normalized, fn (Message $message) => $message instanceof ToolResultMessage);

        expect($toolResultMessages)->toBeEmpty();
    });

    test('a transcript ending in unresolved assistant tool calls is rejected', function () {
        Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('Working.', collect([
                new ToolCall(id: 'call_1', name: 'one', arguments: []),
            ])),
        ]);
    })->throws(InvalidArgumentException::class, 'A transcript must end with a user message.');

    test('matched tool calls and results are preserved as-is', function () {
        $normalized = Transcript::normalize([
            new UserMessage('Go'),
            new AssistantMessage('Working.', collect([
                new ToolCall(id: 'call_1', name: 'one', arguments: []),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(id: 'call_1', name: 'one', arguments: [], result: 'a'),
            ])),
            new UserMessage('Thanks'),
        ]);

        expect($normalized)->toHaveCount(4);

        $assistant = $normalized[1];
        $toolResult = $normalized[2];

        expect($assistant->toolCalls)->toHaveCount(1)
            ->and($toolResult->toolResults)->toHaveCount(1);
    });
});

describe('streaming a transcript with failover', function () {
    test('a transcript threads through provider failover on stream', function () {
        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(fakeGroqStreamBodyForTranscriptFailover(), 200);

        $response = (new AssistantAgent)->stream(
            [
                new UserMessage('History line'),
                new UserMessage('Latest question'),
            ],
            provider: ['primary', 'backup'],
        );

        foreach ($response as $_) {
        }

        expect($response->text)->toBe('Hello');

        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(2);

        $body = json_decode($recorded[1][0]->body(), true);

        expect(collect($body['messages'])->pluck('content'))->toContain('Latest question');
    });
});

function fakeGroqStreamBodyForTranscriptFailover(): string
{
    $chunks = [
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":1,"total_tokens":6}}',
    ];

    $body = '';

    foreach ($chunks as $chunk) {
        $body .= "data: {$chunk}\n\n";
    }

    return $body."data: [DONE]\n\n";
}
