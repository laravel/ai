<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\Sandbox;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\SandboxResolved;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Sandboxes\Exceptions\SandboxBusy;
use Laravel\Ai\Sandboxes\ShellResult;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ApprovingSandboxedAgent;
use Tests\Fixtures\Agents\EphemeralSandboxedAgent;
use Tests\Fixtures\Agents\SandboxedAgent;

beforeEach(function () {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.sandboxes.local.root', $this->root = sys_get_temp_dir().'/ai-sandboxes-'.uniqid());
    Config::set('ai.sandboxes.local.isolate', false);
});

afterEach(fn () => File::deleteDirectory($this->root));

test('a remembered agent finds the file it wrote on the next turn in the local sandbox', function () {
    $user = (object) ['id' => 1];

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'notes/todo.md', 'contents' => 'ship it']),
        'Saved.',
        new ToolCall('call_2', 'Read', ['path' => 'notes/todo.md']),
        'It says ship it.',
    ]);

    $first = (new SandboxedAgent)->forUser($user)->prompt('Save a note');

    expect(File::get("{$this->root}/{$first->conversationId}/notes/todo.md"))->toBe('ship it');

    $second = (new SandboxedAgent)->continue($first->conversationId, $user)->prompt('What does the note say?');

    expect($second->toolResults[0]->result)->toBe('ship it');
});

test('the fake sandbox covers the same flow without touching disk', function () {
    $sandbox = Ai::fakeSandbox(['README.md' => '# Hi']);
    $user = (object) ['id' => 1];

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Edit', ['path' => 'README.md', 'old' => 'Hi', 'new' => 'Hello']),
        'Edited.',
        new ToolCall('call_2', 'Read', ['path' => 'README.md']),
        'It says hello.',
    ]);

    $first = (new SandboxedAgent)->forUser($user)->prompt('Edit the readme');
    $second = (new SandboxedAgent)->continue($first->conversationId, $user)->prompt('Read it back');

    expect($second->toolResults[0]->result)->toBe('# Hello')
        ->and(File::exists($this->root))->toBeFalse();

    $sandbox->assertWrote('README.md')->assertFile('README.md', fn ($contents) => $contents === '# Hello');
});

test('commands run through the sandbox and report their exit code', function () {
    $sandbox = Ai::fakeSandbox()->onExec('npm test', new ShellResult('1 failed', 'Expected 2', 1));

    EphemeralSandboxedAgent::fake([new ToolCall('call_1', 'Bash', ['command' => 'npm test']), 'Tests fail.']);

    $response = (new EphemeralSandboxedAgent)->prompt('Run the tests');

    $sandbox->assertExecuted('npm test');

    expect($response->toolResults[0]->result)->toBe("1 failed\n[stderr]\nExpected 2\n[exit code 1]");
});

test('commands wait for approval by default and run once approved', function () {
    $sandbox = Ai::fakeSandbox()->onExec('rm -rf build', 'removed');
    $user = (object) ['id' => 1];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'rm -rf build']]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Removed the build directory.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $paused = (new ApprovingSandboxedAgent)->forUser($user)->prompt('Clean the build', provider: 'anthropic');

    expect($paused->pendingApprovals)->toHaveCount(1)
        ->and($paused->pendingApprovals[0]->tool)->toBe('Bash');

    $sandbox->assertNothingExecuted();

    $resumed = (new ApprovingSandboxedAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $sandbox->assertExecuted('rm -rf build');

    expect($resumed->toolResults[0]->result)->toBe("removed\n[exit code 0]");
});

test('a turn without a remembered conversation removes its local sandbox afterwards', function () {
    Event::fake([SandboxResolved::class]);

    EphemeralSandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'scratch.txt', 'contents' => 'temporary']),
        'Done.',
    ]);

    (new EphemeralSandboxedAgent)->prompt('Write a scratch file');

    Event::assertDispatched(SandboxResolved::class, fn ($event) => ! File::exists("{$this->root}/{$event->id}"));
    Event::assertDispatchedTimes(SandboxResolved::class, 1);
});

test('a turn that never touches the sandbox does not create one', function () {
    Event::fake([SandboxResolved::class]);

    SandboxedAgent::fake(['Hello.']);

    (new SandboxedAgent)->forUser((object) ['id' => 1])->prompt('Hi');

    Event::assertNotDispatched(SandboxResolved::class);

    expect(File::exists($this->root))->toBeFalse();
});

test('a sandbox another turn is working in cannot be entered', function () {
    $user = (object) ['id' => 1];

    SandboxedAgent::fake(['Hello.', new ToolCall('call_1', 'Read', ['path' => 'a.txt']), 'Done.']);

    $first = (new SandboxedAgent)->forUser($user)->prompt('Hi');

    $lock = Cache::lock("ai:sandbox:{$first->conversationId}", 60);
    $lock->get();

    expect(fn () => (new SandboxedAgent)->continue($first->conversationId, $user)->prompt('Read a.txt'))
        ->toThrow(SandboxBusy::class);

    $lock->release();
});

test('the sandbox is released when a turn ends so the next turn can enter it', function () {
    $user = (object) ['id' => 1];

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'a.txt', 'contents' => 'a']),
        'Done.',
    ]);

    $response = (new SandboxedAgent)->forUser($user)->prompt('Write a.txt');

    expect(Cache::lock("ai:sandbox:{$response->conversationId}", 60)->get())->toBeTrue();
});

test('the sandbox is released when a streamed turn ends', function () {
    $user = (object) ['id' => 1];

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'a.txt', 'contents' => 'a']),
        'Done.',
    ]);

    $stream = (new SandboxedAgent)->forUser($user)->stream('Write a.txt');
    $stream->each(fn () => true);

    expect(File::get("{$this->root}/{$stream->conversationId}/a.txt"))->toBe('a')
        ->and(Cache::lock("ai:sandbox:{$stream->conversationId}", 60)->get())->toBeTrue();
});

test('an agent that forgets its conversations cannot pause for command approval', function () {
    $agent = new #[Sandbox] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You work in a sandbox.';
        }
    };

    Ai::fakeAgent($agent::class, ['Hello.']);

    expect(fn () => $agent->prompt('Hi'))->toThrow(LogicException::class, 'must remember conversations to approve commands');
});

test('the sandbox is released when the consumer abandons a stream', function () {
    SandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'a.txt', 'contents' => 'a']),
        'Done.',
    ]);

    $stream = (new SandboxedAgent)->forUser((object) ['id' => 1])->stream('Write a.txt');

    foreach ($stream as $event) {
        if ($event instanceof ToolResultEvent) {
            break;
        }
    }

    expect(Cache::lock("ai:sandbox:{$stream->conversationId}", 60)->get())->toBeTrue();
});

test('a factory that suspends after each turn stops a kept sandbox once the turn ends', function () {
    $sandbox = Ai::fakeSandbox()->suspendAfterTurn();

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Write', ['path' => 'a.txt', 'contents' => 'a']),
        'Done.',
    ]);

    $response = (new SandboxedAgent)->forUser((object) ['id' => 1])->prompt('Write a.txt');

    $sandbox->assertSuspended($response->conversationId);
});

test('checkpoints of a fake sandbox restore its files', function () {
    $sandbox = Ai::fakeSandbox(['notes.txt' => 'v1']);

    $checkpoint = $sandbox->checkpoint('conversation-1');

    $sandbox->create('conversation-1')->write('notes.txt', 'v2');
    $sandbox->restore('conversation-1', $checkpoint);

    expect($sandbox->create('conversation-1')->read('notes.txt'))->toBe('v1');
});
