<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\TrimConversations;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

function trim_conversation(array $messages, int $keep): array
{
    return (new TrimConversations(keep: $keep))->handle($messages, fn (array $messages) => $messages);
}

test('trim conversations with keep zero retains only the current turn without erroring', function () {
    $messages = [
        new UserMessage('u1'),
        new AssistantMessage('a1'),
        new UserMessage('u2'),
    ];

    $result = trim_conversation($messages, 0);

    expect($result)->toHaveCount(1)
        ->and($result[0]->content)->toBe('u2');
});

test('trim conversations keeps everything when under the limit', function () {
    $messages = [new UserMessage('one'), new AssistantMessage('two')];

    expect(trim_conversation($messages, 5))->toBe($messages);
});

test('trim conversations keeps the last messages snapped to a user turn', function () {
    $messages = [
        new UserMessage('u1'),
        new AssistantMessage('a1'),
        new UserMessage('u2'),
        new AssistantMessage('a2'),
        new UserMessage('u3'),
    ];

    $result = trim_conversation($messages, 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]->content)->toBe('u3')
        ->and($result[0]->role)->toBe(MessageRole::User);
});

test('trim conversations never orphans a tool result from its tool call', function () {
    $messages = [
        new UserMessage('u1'),
        new AssistantMessage('a1'),
        new UserMessage('u2'),
        new AssistantMessage('', new Collection([new ToolCall('c1', 'Clock', [])])),
        new ToolResultMessage(new Collection([new ToolResult('c1', 'Clock', [], '12:00')])),
        new AssistantMessage('done'),
        new UserMessage('u3'),
    ];

    $result = trim_conversation($messages, 2);

    expect($result[0])->toBeInstanceOf(UserMessage::class)
        ->and($result[0]->content)->toBe('u2')
        ->and($result)->toHaveCount(5);

    $toolResultIndex = collect($result)->search(fn ($messages) => $messages instanceof ToolResultMessage);
    expect($result[$toolResultIndex - 1])->toBeInstanceOf(AssistantMessage::class);
});
