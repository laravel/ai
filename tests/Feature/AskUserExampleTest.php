<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\NeedsInput;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;

class AskUser implements NeedsInput, Tool
{
    public function description(): string
    {
        return 'Ask the user a multiple-choice question when you need their preference. Offer 2 to 6 short options. The user may pick several when multiple is true, or type their own answer.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->required(),
            'options' => $schema->array()->items($schema->string())->min(2)->max(6)->required(),
            'multiple' => $schema->boolean()->required(),
        ];
    }

    public function needsInput(JsonSchema $schema, Request $request): array
    {
        return [
            'answers' => $schema->array()
                ->items($schema->string()->max(200))
                ->min(1)
                ->max($request['multiple'] ? count($request['options']) + 1 : 1)
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $answers = $request['answers'];

        $typed = array_values(array_diff($answers, $request['options']));

        return new Response(
            'The user answered: '.implode(', ', $answers).($typed ? '. Typed by the user: '.implode(', ', $typed) : ''),
            data: ['question' => $request['question'], 'answers' => $answers, 'typed' => $typed],
        );
    }
}

class TripPlanner implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You plan trips. When a preference is unclear, call AskUser instead of guessing.';
    }

    public function tools(): iterable
    {
        return [new AskUser];
    }
}

function askUserCall(): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_ask', 'name' => 'AskUser', 'input' => [
            'question' => 'What do you want to do in Lisbon?',
            'options' => ['Food tours', 'Museums', 'Beaches', 'Nightlife'],
            'multiple' => true,
        ]]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function askUserReply(): array
{
    return [
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => 'Here is a 3-day plan...']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

beforeEach(fn () => Config::set('ai.conversations.generate_title', false));

test('the model asks, the user picks several options and types one of their own', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(askUserCall())->push(askUserReply())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    $pending = $paused->pendingApprovals->sole();

    expect($pending->isInteractive())->toBeTrue()
        ->and($pending->arguments['options'])->toBe(['Food tours', 'Museums', 'Beaches', 'Nightlife'])
        ->and($pending->schema['required'])->toBe(['answers']);

    $resumed = (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from([$pending->id => Decision::submit(['answers' => ['Food tours', 'Fado night']])]),
        provider: 'anthropic',
    );

    $result = $resumed->toolResults->firstWhere('id', 'toolu_ask');

    expect($result->result)->toBe('The user answered: Food tours, Fado night. Typed by the user: Fado night')
        ->and($result->data['typed'])->toBe(['Fado night'])
        ->and((string) $resumed)->toBe('Here is a 3-day plan...');
});

test('an answer without the answers key is refused before the tool runs', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(askUserCall())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => true]),
        provider: 'anthropic',
    );
})->throws(ValidationException::class, 'The answers field is required.');

test('the user may rewrite the question and options along with the answer', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(askUserCall())->push(askUserReply())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    $resumed = (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => Decision::edit([
            'question' => 'What do you want to do in Porto?',
            'options' => ['Wine cellars', 'River cruise'],
            'multiple' => false,
            'answers' => ['Wine cellars'],
        ])]),
        provider: 'anthropic',
    );

    expect($resumed->toolResults->firstWhere('id', 'toolu_ask')->result)->toBe('The user answered: Wine cellars');
});

test('an edit is held to the input schema of its edited arguments', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(askUserCall())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => Decision::edit([
            'question' => 'What do you want to do in Porto?',
            'options' => ['Wine cellars', 'River cruise'],
            'multiple' => false,
            'answers' => ['Wine cellars', 'River cruise'],
        ])]),
        provider: 'anthropic',
    );
})->throws(ValidationException::class, 'The answers field must not have more than 1 items.');
