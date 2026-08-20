<?php

use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ChatInput;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\QueuedAgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAgent;

function chatInput(?UserMessage $message = null, ?Decisions $decisions = null): ChatInput
{
    return new class($message, $decisions) implements ChatInput
    {
        public function __construct(private ?UserMessage $message, private ?Decisions $decisions) {}

        public function message(): ?UserMessage
        {
            return $this->message;
        }

        public function decisions(): ?Decisions
        {
            return $this->decisions;
        }
    };
}

test('a user message may be given as the prompt', function () {
    AssistantAgent::fake(['Hello there.']);

    $response = (new AssistantAgent)->prompt(
        new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')])
    );

    expect($response->text)->toBe('Hello there.');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
        && $prompt->attachments->count() === 1
        && $prompt->attachments->first() instanceof Base64Image);
});

test('a user message may be given as the prompt when streaming', function () {
    AssistantAgent::fake(['Hello there.']);

    iterator_to_array((new AssistantAgent)->stream(new UserMessage('Hello')));

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Hello');
});

test('chat input resolves to its user message', function () {
    AssistantAgent::fake(['Hello there.']);

    (new AssistantAgent)->prompt(chatInput(message: new UserMessage('From chat input')));

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'From chat input');
});

test('chat input resolves to its approval decisions before its user message', function () {
    Queue::fake();
    AssistantAgent::fake();

    (new AssistantAgent)->queue(chatInput(
        message: new UserMessage('Ignored'),
        decisions: Decision::approveAll(),
    ));

    AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
});

test('empty chat input is rejected', function () {
    AssistantAgent::fake();

    (new AssistantAgent)->prompt(chatInput());
})->throws(InvalidArgumentException::class, 'The chat input contains no user message or approval decisions.');

test('ad-hoc message history is sent ahead of the prompt', function () {
    AssistantAgent::fake(['How can I help?']);

    $history = [
        new UserMessage('Hello'),
        new AssistantMessage('Hi! How can I help you today?'),
    ];

    (new AssistantAgent)->withMessages($history)->prompt('What is Laravel?');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages === $history
        && $prompt->prompt === 'What is Laravel?');
});

test('ad-hoc message history may be given as raw arrays', function () {
    AssistantAgent::fake(['Sure.']);

    (new AssistantAgent)
        ->withMessages([['role' => 'user', 'content' => 'Hello']])
        ->prompt('Continue');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => count($prompt->messages) === 1
        && $prompt->messages[0]->content === 'Hello');
});

test('ad-hoc message history may be given as UI message arrays', function () {
    AssistantAgent::fake(['Sure.']);

    (new AssistantAgent)
        ->withMessages([['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hello']]]])
        ->prompt('Continue');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => count($prompt->messages) === 1
        && $prompt->messages[0]->content === 'Hello');
});

test('ad-hoc message history may not be combined with a conversational agent', function () {
    (new ConversationalAgent)->withMessages([new UserMessage('Hello')]);
})->throws(LogicException::class);

test('ad-hoc message history does not leak into a later prompt', function () {
    AssistantAgent::fake(['First.', 'Second.']);

    $agent = new AssistantAgent;

    $agent->withMessages([new UserMessage('Hello')])->prompt('First prompt');
    $agent->prompt('Second prompt');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt'
        && count($prompt->messages) === 1);

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt'
        && $prompt->messages === null);
});

test('chat input resolves to its approval decisions when broadcasting on the queue', function () {
    Queue::fake();
    AssistantAgent::fake();

    (new AssistantAgent)->broadcastOnQueue(chatInput(
        message: new UserMessage('Ignored'),
        decisions: Decision::approveAll(),
    ), []);

    AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
});

test('a user message keeps its attachments when broadcasting on the queue', function () {
    Queue::fake();
    AssistantAgent::fake();

    (new AssistantAgent)->broadcastOnQueue(
        new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')]), []
    );

    AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
        && count($prompt->attachments) === 1
        && $prompt->attachments[0] instanceof Base64Image);
});
