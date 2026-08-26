<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\RememberingConversationalAgent;

beforeEach(function () {
    Config::set('ai.conversations.generate_title', false);
});

function adHocSeed(): array
{
    return [
        new UserMessage('Seed question'),
        new AssistantMessage('Seed answer'),
    ];
}

function storedConversationMessages(): array
{
    return DB::table('agent_conversation_messages')
        ->orderBy('id')
        ->get(['role', 'content'])
        ->map(fn (object $message): array => [$message->role, $message->content])
        ->all();
}

function anthropicMessageTexts(): array
{
    $body = json_decode(Http::recorded()->last()[0]->body(), true);

    return array_map(
        fn (array $message): array => [$message['role'], $message['content'][0]['text']],
        $body['messages'],
    );
}

test('ad-hoc messages are sent ahead of the prompt but never stored in the conversation', function () {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse('Laravel is a framework.')]);

    (new RememberingConversationalAgent)
        ->forUser((object) ['id' => 7])
        ->withMessages(adHocSeed())
        ->prompt('What is Laravel?', provider: 'anthropic');

    expect(anthropicMessageTexts())->toBe([
        ['user', 'Seed question'],
        ['assistant', 'Seed answer'],
        ['user', 'What is Laravel?'],
    ]);

    expect(storedConversationMessages())->toBe([
        ['user', 'What is Laravel?'],
        ['assistant', 'Laravel is a framework.'],
    ]);
});

test('a replayed seed precedes stored history on a continued conversation and is still not stored', function () {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse('Answer.')]);

    $first = (new RememberingConversationalAgent)
        ->forUser((object) ['id' => 7])
        ->withMessages(adHocSeed())
        ->prompt('First prompt', provider: 'anthropic');

    (new RememberingConversationalAgent)
        ->continue($first->conversationId, (object) ['id' => 7])
        ->withMessages(adHocSeed())
        ->prompt('Second prompt', provider: 'anthropic');

    expect(anthropicMessageTexts())->toBe([
        ['user', 'Seed question'],
        ['assistant', 'Seed answer'],
        ['user', 'First prompt'],
        ['assistant', 'Answer.'],
        ['user', 'Second prompt'],
    ]);

    expect(storedConversationMessages())->toBe([
        ['user', 'First prompt'],
        ['assistant', 'Answer.'],
        ['user', 'Second prompt'],
        ['assistant', 'Answer.'],
    ]);
});

test('ad-hoc messages are sent ahead of the prompt but never stored when streaming', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response($this->ssePayload([
            $this->messageStart(),
            $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
            $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Streamed.']),
            $this->contentBlockStop(0),
            $this->messageDelta('end_turn', 5),
            ['type' => 'message_stop'],
        ])),
    ]);

    iterator_to_array(
        (new RememberingConversationalAgent)
            ->forUser((object) ['id' => 7])
            ->withMessages(adHocSeed())
            ->stream('What is Laravel?', provider: 'anthropic')
    );

    expect(anthropicMessageTexts())->toBe([
        ['user', 'Seed question'],
        ['assistant', 'Seed answer'],
        ['user', 'What is Laravel?'],
    ]);

    expect(storedConversationMessages())->toBe([
        ['user', 'What is Laravel?'],
        ['assistant', 'Streamed.'],
    ]);
});
