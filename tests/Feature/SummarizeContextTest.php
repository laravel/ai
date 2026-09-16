<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Agents\SummarizeConversationAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\SummarizeContext;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAssistantAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function conversationOf(int $pairs): array
{
    return (new Collection(range(1, $pairs)))
        ->flatMap(fn (int $index): array => [
            new UserMessage(str_repeat("question {$index} ", 20)),
            new AssistantMessage(str_repeat("answer {$index} ", 20)),
        ])->all();
}

function capturingMiddleware(array &$steps): Closure
{
    return function (PendingStep $step, Closure $next) use (&$steps) {
        $steps[] = $step;

        return $next($step);
    };
}

function captureSummaryPrompts(array &$prompts): void
{
    SummarizeConversationAgent::fake(function (string $prompt) use (&$prompts): string {
        $prompts[] = $prompt;

        return 'SUMMARY-'.count($prompts);
    });
}

test('history beyond the retained window is compacted into a summary message', function (): void {
    AssistantAgent::fake(['Fake response']);
    SummarizeConversationAgent::fake(['Concise summary.']);

    $steps = [];

    (new AssistantAgent)
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 2), capturingMiddleware($steps)])
        ->prompt('Latest question');

    expect($steps[0]->messages)->toHaveCount(4)
        ->and($steps[0]->messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($steps[0]->messages[0]->content)->toEndWith(PHP_EOL.'Concise summary.')
        ->and($steps[0]->messages[1]->content)->toStartWith('question 6')
        ->and($steps[0]->messages[3]->content)->toBe('Latest question')
        ->and($steps[0]->instructions)->toBe((new AssistantAgent)->instructions());

    SummarizeConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'question 1')
        && str_contains($prompt->prompt, 'answer 5')
        && ! str_contains($prompt->prompt, 'question 6'));
});

test('history is left untouched while it fits the retained window', function (): void {
    AssistantAgent::fake(['Fake response']);
    SummarizeConversationAgent::fake();

    $steps = [];

    (new AssistantAgent)
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 7), capturingMiddleware($steps)])
        ->prompt('Latest question');

    expect($steps[0]->messages)->toHaveCount(13);

    SummarizeConversationAgent::assertNeverPrompted();
});

test('the retained window always starts with a user turn', function (): void {
    AssistantAgent::fake(['Fake response']);
    SummarizeConversationAgent::fake(['Concise summary.']);

    $messages = [
        ...conversationOf(5),
        new AssistantMessage('', new Collection([new ToolCall('call_1', 'NamedTool', [])])),
        new ToolResultMessage(new Collection([new ToolResult('call_1', 'NamedTool', [], 'ok')])),
        new AssistantMessage(str_repeat('final answer ', 20)),
    ];

    $steps = [];

    (new AssistantAgent)
        ->withMessages($messages)
        ->withMiddleware([new SummarizeContext(turns: 1), capturingMiddleware($steps)])
        ->prompt('Latest question');

    expect($steps[0]->messages)->toHaveCount(2)
        ->and($steps[0]->messages[0]->content)->toContain('Concise summary.')
        ->and($steps[0]->messages[1]->content)->toBe('Latest question');

    SummarizeConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'call_1')
        && str_contains($prompt->prompt, 'final answer'));
});

test('a tool loop compacts once and keeps its own tool turns in view', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);
    SummarizeConversationAgent::fake(['Concise summary.']);

    $steps = [];

    (new AssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 2), capturingMiddleware($steps)])
        ->prompt('Latest question');

    expect($steps)->toHaveCount(2)
        ->and($steps[1]->messages)->toHaveCount(6)
        ->and($steps[1]->messages[0]->content)->toContain('Concise summary.')
        ->and($steps[1]->messages[1]->content)->toStartWith('question 6')
        ->and($steps[1]->messages[4]->toolCalls[0]->id)->toBe('call_1')
        ->and($steps[1]->messages[5])->toBeInstanceOf(ToolResultMessage::class);

    SummarizeConversationAgent::assertPromptedTimes(1);
});

test('a reused middleware instance does not carry its boundary into the next run', function (): void {
    AssistantAgent::fake(['Fake response', 'Fake response']);
    SummarizeConversationAgent::fake(['Concise summary.']);

    $steps = [];

    $agent = (new AssistantAgent)
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 2), capturingMiddleware($steps)]);

    $agent->prompt('Latest question');

    $agent->withMessages([new UserMessage('Only message')])->prompt('Latest question');

    expect($steps[0]->messages)->toHaveCount(4)
        ->and($steps[1]->messages)->toHaveCount(2);
});

test('the summary is written by the running provider on its cheapest model unless told otherwise', function (): void {
    AssistantAgent::fake(['Fake response']);
    SummarizeConversationAgent::fake();

    (new AssistantAgent)
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 2)])
        ->prompt('Latest question', provider: 'anthropic');

    SummarizeConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'anthropic'
        && $prompt->model === $prompt->provider->cheapestTextModel());
});

test('the summarizing provider, model and timeout can be chosen like the summarize macro', function (): void {
    AssistantAgent::fake(['Fake response']);
    SummarizeConversationAgent::fake();

    (new AssistantAgent)
        ->withMessages(conversationOf(6))
        ->withMiddleware([new SummarizeContext(turns: 2, provider: Lab::OpenAI, model: 'gpt-summary', timeout: 7)])
        ->prompt('Latest question', provider: 'anthropic');

    SummarizeConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'openai'
        && $prompt->model === 'gpt-summary'
        && $prompt->timeout === 7);
});

test('a cached summary is reused so only the newly displaced messages are summarized', function (): void {
    Config::set(['ai.conversations.generate_title' => false, 'ai.caching.summaries.store' => 'array']);

    $prompts = [];

    ConversationalAssistantAgent::fake(fn (string $prompt): string => 'Answer to "'.$prompt.'" padded padded padded padded');
    captureSummaryPrompts($prompts);

    $agent = (new ConversationalAssistantAgent)->forUser(new class
    {
        public int $id = 1;
    });

    $summarize = new SummarizeContext(turns: 2);

    foreach (['Turn one', 'Turn two', 'Turn three', 'Turn four'] as $turn) {
        $agent->withMiddleware([$summarize])->prompt($turn);
    }

    expect($prompts)->toHaveCount(2)
        ->and($prompts[0])->toContain('Turn one')->not->toContain('Summary so far')
        ->and($prompts[1])->toContain('Summary so far: SUMMARY-1')
        ->and($prompts[1])->toContain('Turn two')->not->toContain('Turn one');
});

test('a cached summary survives the history window sliding past its first message', function (): void {
    Config::set(['ai.conversations.generate_title' => false, 'ai.caching.summaries.store' => 'array']);

    $prompts = [];

    $agent = new class extends ConversationalAssistantAgent
    {
        protected function maxConversationMessages(): int
        {
            return 4;
        }
    };

    $agent::fake(fn (string $prompt): string => 'Answer to "'.$prompt.'" padded padded padded padded');
    captureSummaryPrompts($prompts);

    $agent = $agent->forUser(new class
    {
        public int $id = 3;
    });

    $summarize = new SummarizeContext(turns: 1);

    foreach (['Turn one', 'Turn two', 'Turn three', 'Turn four'] as $turn) {
        $agent->withMiddleware([$summarize])->prompt($turn);
    }

    expect($prompts)->toHaveCount(3)
        ->and($prompts[2])->toContain('Summary so far: SUMMARY-2')
        ->and($prompts[2])->toContain('Turn three')->not->toContain('Turn two');
});

test('a cached summary that no longer matches the history is rebuilt', function (): void {
    Config::set(['ai.conversations.generate_title' => false, 'ai.caching.summaries.store' => 'array']);

    $prompts = [];

    ConversationalAssistantAgent::fake(fn (string $prompt): string => 'Answer to "'.$prompt.'" padded padded padded padded');
    captureSummaryPrompts($prompts);

    $agent = (new ConversationalAssistantAgent)->forUser(new class
    {
        public int $id = 2;
    });

    $summarize = new SummarizeContext(turns: 2);

    $agent->withMiddleware([$summarize])->prompt('Turn one');
    $agent->withMiddleware([$summarize])->prompt('Turn two');

    Cache::store('array')->put('laravel-ai:summary:'.$agent->currentConversation(), ['summary' => 'STALE', 'tail' => 'no-longer-matching'], 60);

    $agent->withMiddleware([$summarize])->prompt('Turn three');

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('Turn one')->not->toContain('STALE');
});
