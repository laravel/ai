<?php

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\PermissionMode;

beforeEach(function () {
    $this->run = new HarnessRun('Agent', '', '', 'sonnet', 'session', base_path(), PermissionMode::Default, [], 10, 'run');
    $this->store = new ApprovalStore(Cache::store('array'), 60);
});

it('binds approval decisions to session tool and exact input', function () {
    $pending = $this->store->check($this->run, 'Bash', ['command' => 'ls']);
    $this->store->resolve($this->run, Decisions::from([$pending['pending_id'] => true]));

    expect($this->store->check($this->run, 'Bash', ['command' => 'ls']))->toBe(['behavior' => 'allow', 'updatedInput' => ['command' => 'ls']])
        ->and($this->store->check($this->run, 'Bash', ['command' => 'pwd'])['behavior'])->toBe('deny')
        ->and($this->store->check($this->run, 'Read', ['command' => 'ls'])['behavior'])->toBe('deny');
    $other = clone $this->run;
    $other->sessionId = 'other';
    expect($this->store->check($other, 'Bash', ['command' => 'ls'])['behavior'])->toBe('deny');
});

it('returns edited input and clears grants at the end of a turn', function () {
    $pending = $this->store->check($this->run, 'Write', ['text' => 'before']);
    $this->store->resolve($this->run, Decisions::from([$pending['pending_id'] => Decision::edit(['text' => 'after'])]));

    expect($this->store->check($this->run, 'Write', ['text' => 'before']))->toBe(['behavior' => 'allow', 'updatedInput' => ['text' => 'after']]);
    $this->store->clearDecisions($this->run);
    expect($this->store->check($this->run, 'Write', ['text' => 'after'])['behavior'])->toBe('deny');
});

it('expires pending approvals and refuses unknown decisions atomically', function () {
    $pending = $this->store->check($this->run, 'Bash', []);
    expect(fn () => $this->store->resolve($this->run, Decisions::from([$pending['pending_id'] => true, 'unknown' => true])))->toThrow(LogicException::class);
    expect($this->store->pending($this->run))->toHaveCount(1);
    $this->travel(61)->seconds();
    expect(fn () => $this->store->resolve($this->run, Decision::approveAll()))->toThrow(LogicException::class, 'expired');
});

it('keeps rejected tools blocked with the user reason', function () {
    $this->store->check($this->run, 'Bash', []);
    $this->store->resolve($this->run, Decision::rejectAll('Do not run this.'));
    expect($this->store->check($this->run, 'Bash', []))->toBe(['behavior' => 'deny', 'message' => 'Do not run this.']);
});
