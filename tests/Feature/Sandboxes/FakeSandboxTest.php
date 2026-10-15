<?php

use Laravel\Ai\Facades\Sandbox;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;
use PHPUnit\Framework\AssertionFailedError;

test('a service can create, store, reattach to and delete a sandbox against the fake', function () {
    $fake = Sandbox::fake(['README.md' => '# Hello'])->onExec('npm test', new ShellResult('1 passed', '', 0));

    $sandbox = Sandbox::create(['image' => 'node:22']);
    $sandbox->write('result.txt', 'done');

    $attached = Sandbox::get($sandbox->id());

    expect($attached->exec('npm test')->stdout)->toBe('1 passed')
        ->and($attached->read('README.md'))->toBe('# Hello')
        ->and($attached->read('result.txt'))->toBe('done');

    Sandbox::delete($sandbox->id());

    $fake->assertCreated(fn (array $options) => $options['image'] === 'node:22')
        ->assertExecuted('npm test', $sandbox->id())
        ->assertWrote('result.txt')
        ->assertFile('result.txt', fn ($contents) => $contents === 'done')
        ->assertDeleted($sandbox->id());

    expect(fn () => Sandbox::get($sandbox->id()))->toThrow(SandboxNotFound::class)
        ->and(fn () => $sandbox->read('result.txt'))->toThrow(SandboxNotFound::class);
});

test('fake sandboxes are independent, and seeded files apply to each new one', function () {
    $fake = Sandbox::fake(['seed.txt' => 'seed'])->onExec('*', 'ok');

    $first = Sandbox::create();
    $second = Sandbox::create();

    $first->write('only-first.txt', 'x');
    $first->exec('make');

    expect($second->exists('only-first.txt'))->toBeFalse()
        ->and($second->read('seed.txt'))->toBe('seed');

    $fake->assertExecuted('make', $first->id())->assertNothingExecuted($second->id());

    expect(fn () => $fake->assertExecuted('make', $second->id()))->toThrow(AssertionFailedError::class);
});

test('unscripted commands fail loudly instead of succeeding', function () {
    Sandbox::fake();

    expect(fn () => Sandbox::create()->exec('rm -rf /'))->toThrow(SandboxException::class, 'unscripted command [rm -rf /]');
});

test('scripted commands can stream chunks, time out, or fail with resource loss', function () {
    $fake = Sandbox::fake()
        ->onExec('build', function (string $command, Closure $output) {
            $output('stdout', "step 1\n");
            $output('stderr', "warning\n");

            return new ShellResult("step 1\n", "warning\n", 0);
        })
        ->onExec('slow', new ShellResult('', '', 124, timedOut: true))
        ->onExec('gone', SandboxNotFound::for('fake', 'x'));

    $sandbox = Sandbox::create();
    $chunks = [];

    $sandbox->exec('build', onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[] = "{$type}:{$chunk}";
    });

    expect($chunks)->toBe(["stdout:step 1\n", "stderr:warning\n"])
        ->and($sandbox->exec('slow')->timedOut)->toBeTrue()
        ->and(fn () => $sandbox->exec('gone'))->toThrow(SandboxNotFound::class);
});

test('suspension and checkpoints follow the same rules as real providers', function () {
    $fake = Sandbox::fake()->onExec('*', 'ok');

    $sandbox = Sandbox::create();
    $sandbox->write('notes.txt', 'v1');

    $checkpoint = $fake->checkpoint($sandbox->id());
    $sandbox->write('notes.txt', 'v2');

    $fake->suspend($sandbox->id());

    expect($sandbox->state())->toBe(SandboxState::Stopped)
        ->and(fn () => $sandbox->exec('true'))->toThrow(SandboxStateException::class);

    $fake->resume($sandbox->id());

    expect(fn () => $fake->resume($sandbox->id()))->toThrow(SandboxStateException::class)
        ->and($fake->restore($sandbox->id(), $checkpoint)->read('notes.txt'))->toBe('v1');

    $fake->forgetCheckpoint($sandbox->id(), $checkpoint);

    expect(fn () => $fake->restore($sandbox->id(), $checkpoint))->toThrow(SandboxException::class);

    $fake->assertSuspended($sandbox->id());
});

test('unknown create options are rejected by the fake', function () {
    Sandbox::fake();

    expect(fn () => Sandbox::create(['imgae' => 'node:22']))->toThrow(UnsupportedOptionException::class, '[imgae]');
});
