<?php

use Illuminate\Support\Str;
use Laravel\Ai\Harness\ClaudeCode\ClaudeCodeRuntime;
use Laravel\Ai\Harness\HarnessException;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\PermissionMode;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

beforeEach(function () {
    $this->run = new HarnessRun('Agent', 'custom instructions', 'hello $world; `test`', 'sonnet', (string) Str::uuid(), base_path(), PermissionMode::BypassPermissions, [], 5, 'run');
    $this->config = ['binary' => __DIR__.'/../../Fixtures/claude/replay'];
});

it('uses argv and stdin safely and reads fragmented output without a final newline', function () {
    $runtime = new ClaudeCodeRuntime($this->config);
    $generator = $runtime->run($this->run);
    $events = iterator_to_array($generator);
    expect(TextDelta::combine($events))->toBe($this->run->prompt)
        ->and($generator->getReturn()->usage->promptTokens)->toBe(7)
        ->and(end($events)->toArray()['meta']['session_id'])->toBe($this->run->sessionId);
    $command = $runtime->command($this->run);
    expect($command)->toContain('--session-id', '--include-partial-messages', '--append-system-prompt', 'custom instructions')->not->toContain($this->run->prompt, '--resume');
    $this->run->resume = true;
    expect($runtime->command($this->run))->toContain('--resume')->not->toContain('--session-id');
});

it('emits an error and throws without a successful stream end', function (string $mode, string $message) {
    $runtime = new ClaudeCodeRuntime([...$this->config, 'env' => ['HARNESS_TEST_MODE' => $mode]]);
    $events = [];

    try {
        foreach ($runtime->run($this->run) as $event) {
            $events[] = $event;
        }
        test()->fail('Expected a harness failure.');
    } catch (HarnessException $exception) {
        expect($exception->getMessage())->toContain($message)->and($exception->sessionId)->toBe($this->run->sessionId);
    }

    expect(collect($events)->whereInstanceOf(Error::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(0);
})->with([['error', 'fixture API failure'], ['exit', 'code 7'], ['missing', 'without a result']]);

it('terminates a timed out process', function () {
    $pidFile = tempnam(sys_get_temp_dir(), 'harness-pid');
    $this->run->timeout = 1;
    $runtime = new ClaudeCodeRuntime([...$this->config, 'env' => ['HARNESS_TEST_MODE' => 'timeout', 'HARNESS_TEST_PID' => $pidFile]]);

    try {
        expect(fn () => iterator_to_array($runtime->run($this->run)))->toThrow(HarnessException::class, 'timeout');
        $pid = (int) file_get_contents($pidFile);
        expect($pid)->toBeGreaterThan(0)->and(posix_kill($pid, 0))->toBeFalse();
    } finally {
        unlink($pidFile);
    }
});
