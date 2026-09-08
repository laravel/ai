<?php

use Illuminate\Http\Request;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Attributes\Harness;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Harness\HarnessAgent;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Tests\Fixtures\Harness\WriteNote;

it('resolves harness attributes and resumes the caller session', function () {
    $fake = Ai::fakeHarness(['first', 'second']);
    $agent = new #[Harness('custom'), Model('opus'), Timeout(42)] class extends HarnessAgent {};
    $first = $agent->prompt('start');
    $second = $agent->prompt('continue', $first->sessionId);

    expect($second->text)->toBe('second')->and($second->sessionId)->toBe($first->sessionId)
        ->and($second->meta->toArray()['session_id'])->toBe($first->sessionId);
    $fake->assertRan(fn (HarnessRun $run) => $run->harness === 'custom' && $run->model === 'opus' && $run->timeout === 42 && $run->resume && $run->prompt === 'continue');
    $fake->assertRunCount(2);
});

it('collects fake usage and tool events into a synchronous response', function () {
    Ai::fakeHarness([[
        new TextDelta('event', 'message', 'Hello', 1),
        new StreamEnd('end', 'length', new Usage(12, 4, 2, 3), 1),
    ]]);

    $response = (new class extends HarnessAgent {})->prompt('hello');

    expect($response->toArray())->toMatchArray([
        'text' => 'Hello', 'usage' => (new Usage(12, 4, 2, 3))->toArray(), 'finish_reason' => 'length',
    ]);
});

it('streams through the existing protocols without executing twice', function (string $protocol, string $expected) {
    $fake = Ai::fakeHarness(['Hello harness']);
    $stream = (new class extends HarnessAgent {})->stream('hello');
    $fake->assertNothingRan();
    $response = $stream->$protocol()->toResponse(Request::create('/'));
    $content = '';
    ob_start(function ($buffer) use (&$content) {
        $content .= $buffer;

        return '';
    });
    $response->sendContent();
    ob_end_clean();

    expect($content)->toContain($expected)->toContain('Hello harness')->toContain($fake->runs[0]->sessionId);
    iterator_to_array($stream);
    $fake->assertRunCount(1);
})->with([
    ['usingVercelDataProtocol', 'text-delta'],
    ['usingAgentUserInteractionProtocol', 'TEXT_MESSAGE_CONTENT'],
]);

it('requires a session for decisions and validates session ids', function () {
    $agent = new class extends HarnessAgent {};
    expect(fn () => $agent->prompt(Decision::approveAll()))->toThrow(LogicException::class, 'session ID')
        ->and(fn () => $agent->prompt('hello', '--bad'))->toThrow(LogicException::class, 'UUID');
});

it('rejects approvable tools when permissions are bypassed', function () {
    $agent = new class extends HarnessAgent
    {
        public function tools(): iterable
        {
            return [new WriteNote];
        }
    };

    expect(fn () => $agent->prompt('write'))->toThrow(LogicException::class, 'Approvable');
});

it('generates a harness agent', function () {
    $path = app_path('Ai/Harnesses/GeneratedHarness.php');

    try {
        $this->artisan('make:harness-agent', ['name' => 'GeneratedHarness'])->assertSuccessful();
        expect(file_get_contents($path))->toContain('class GeneratedHarness extends HarnessAgent');
    } finally {
        @unlink($path);
    }
});

it('resumes scripted fake approvals with the same public decisions API', function () {
    $fake = Ai::fakeHarness([
        [new ToolApprovalRequest('event', collect([
            new PendingApproval('approval', 'Bash', ['command' => 'ls']),
        ]), time())],
        'Completed.',
    ]);
    $agent = new class extends HarnessAgent {};
    $paused = $agent->prompt('Inspect files.');
    $resumed = $agent->prompt(Decision::approveAll(), $paused->sessionId);
    expect($resumed->text)->toBe('Completed.');
    $fake->assertRan(fn ($run) => $run->resume && str_contains($run->prompt, '"behavior":"allow"'));
});
