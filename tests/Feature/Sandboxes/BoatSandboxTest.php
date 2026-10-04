<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\BoatProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\SandboxState;

beforeEach(function () {
    Sleep::fake();

    $this->provider = new BoatProvider(['name' => 'boat', 'driver' => 'boat', 'key' => 'boat-key', 'timeout' => 30]);
});

function boatSandbox(string $id, string $state): array
{
    return ['ok' => true, 'type' => 'sandbox.info', 'sandbox' => ['id' => $id, 'name' => 'Sandbox', 'state' => $state, 'desktopAvailable' => false, 'snapshotAvailable' => false]];
}

test('a sandbox is provisioned with an idempotency key and waited on until it runs', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['ok' => true, 'type' => 'sandbox.created', 'sandbox' => ['id' => 'bx_23456789', 'state' => 'provisioning']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::sequence()
            ->push(boatSandbox('bx_23456789', 'provisioning'))
            ->whenEmpty(Http::response(boatSandbox('bx_23456789', 'idle'))),
    ]);

    $sandbox = $this->provider->create(['env' => ['GREETING' => 'hi'], 'ttl' => 60, 'type' => 'large']);

    expect($sandbox->id())->toBe('bx_23456789')
        ->and($sandbox->cwd())->toBe('/home/user')
        ->and($sandbox->state())->toBe(SandboxState::Running);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/sandboxes'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer boat-key')
        && $request->hasHeader('Idempotency-Key')
        && $request['noEnv'] === true
        && $request['type'] === 'large'
        && $request['ttlSeconds'] === 60
        && $request['env'] === ['GREETING' => 'hi']);
});

test('options Boat cannot honor are refused before anything is provisioned', function () {
    Http::fake();

    expect(fn () => $this->provider->create(['network' => false]))->toThrow(UnsupportedOptionException::class, 'always have network')
        ->and(fn () => $this->provider->create(['cpus' => 2]))->toThrow(UnsupportedOptionException::class, '[cpus]');

    Http::assertNothingSent();
});

test('attaching queries the sandbox by its own ID and never provisions or resumes one', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'archived')),
        'boat.dev/api/v1/sandboxes/bx_aaaaaaaa' => Http::response(['ok' => false, 'code' => 'not_found'], 404),
    ]);

    $sandbox = (new BoatProvider(['driver' => 'boat', 'key' => 'boat-key']))->get('bx_23456789');

    expect($sandbox->state())->toBe(SandboxState::Stopped)
        ->and(fn () => $this->provider->get('bx_aaaaaaaa'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->get('not-a-boat-id'))->toThrow(SandboxNotFound::class);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('commands carry their environment and directory, and report stopped or missing sandboxes', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'idle')),
        'boat.dev/api/v1/sandboxes/bx_23456789/commands' => Http::sequence()
            ->push(['ok' => true, 'type' => 'command.finished', 'success' => true, 'exitCode' => 0, 'stdout' => "hi\n", 'stderr' => '', 'timedOut' => false])
            ->push(['ok' => false, 'status' => 409, 'code' => 'sandbox_not_ready', 'state' => 'archived', 'message' => 'Sandbox is stopped.'], 409)
            ->push(['ok' => false, 'status' => 404, 'code' => 'not_found', 'message' => 'Gone.'], 404),
    ]);

    $sandbox = $this->provider->get('bx_23456789');

    expect($sandbox->exec('echo $GREETING', env: ['GREETING' => "it's"])->stdout)->toBe("hi\n");

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/commands')
        && $request['command'] === "export GREETING='it'\\''s'; cd -- '/home/user' && echo \$GREETING"
        && $request['timeoutSeconds'] === 30);

    expect(fn () => $sandbox->exec('true'))->toThrow(SandboxStateException::class)
        ->and(fn () => $sandbox->exec('true'))->toThrow(SandboxNotFound::class);
});

test('command output streams from Boat newline-delimited frames', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'idle')),
        'boat.dev/api/v1/sandboxes/bx_23456789/commands' => Http::response(implode("\n", [
            '{"type":"started"}',
            '{"type":"stdout","data":"one\n"}',
            '{"type":"stderr","data":"two\n"}',
            '{"type":"stdout","data":"three\n"}',
            '{"type":"exit","exitCode":3,"timedOut":false}',
        ])),
    ]);

    $chunks = [];

    $result = $this->provider->get('bx_23456789')->exec('make', onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[] = "{$type}:{$chunk}";
    });

    expect($chunks)->toBe(["stdout:one\n", "stderr:two\n", "stdout:three\n"])
        ->and($result->stdout)->toBe("one\nthree\n")
        ->and($result->stderr)->toBe("two\n")
        ->and($result->exitCode)->toBe(3);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/commands') && $request['stream'] === true);
});

test('files are read and written through the file API as base64', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'ready')),
        'boat.dev/api/v1/sandboxes/bx_23456789/commands' => Http::response(['exitCode' => 0, 'stdout' => "directory|4096|0\n", 'stderr' => '', 'timedOut' => false]),
        'boat.dev/api/v1/sandboxes/bx_23456789/files*' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response(['ok' => true, 'type' => 'file.written'])
            : Http::response(['ok' => true, 'type' => 'file.read', 'content' => base64_encode("\x00binary")]),
    ]);

    $sandbox = $this->provider->get('bx_23456789');

    $sandbox->write('notes.txt', "line\n");

    expect($sandbox->read('image.bin'))->toBe("\x00binary");

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['path'] === '/home/user/notes.txt'
        && $request['content'] === base64_encode("line\n")
        && $request['encoding'] === 'base64');
});

test('suspend and resume stop and start the sandbox, and resume refuses a running one', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789/stop' => Http::response(['ok' => true, 'type' => 'sandbox.stopping'], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789/resume' => Http::response(['ok' => true, 'type' => 'sandbox.resuming'], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::sequence()
            ->push(boatSandbox('bx_23456789', 'idle'))
            ->push(boatSandbox('bx_23456789', 'archived'))
            ->push(boatSandbox('bx_23456789', 'provisioning'))
            ->push(boatSandbox('bx_23456789', 'idle'))
            ->push(boatSandbox('bx_23456789', 'idle')),
    ]);

    $this->provider->suspend('bx_23456789');
    $this->provider->resume('bx_23456789');

    expect(fn () => $this->provider->resume('bx_23456789'))->toThrow(SandboxStateException::class);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/stop'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/resume'));
});

test('restoring a checkpoint returns a new sandbox started from the snapshot and keeps the checkpoints', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['sandbox' => ['id' => 'bx_bbbbbbbb', 'state' => 'provisioning']], 202),
        'boat.dev/api/v1/sandboxes/bx_aaaaaaaa' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(['ok' => true], 202)
            : Http::response(boatSandbox('bx_aaaaaaaa', 'idle')),
        'boat.dev/api/v1/sandboxes/bx_bbbbbbbb' => Http::response(boatSandbox('bx_bbbbbbbb', 'ready')),
        'boat.dev/api/v1/named-snapshots' => Http::response(['ok' => true, 'snapshot' => ['status' => 'saving']], 202),
        'boat.dev/api/v1/named-snapshots/*' => Http::sequence()
            ->push(['snapshot' => ['status' => 'saving']])
            ->whenEmpty(Http::response(['snapshot' => ['status' => 'ready']])),
    ]);

    $checkpoint = $this->provider->checkpoint('bx_aaaaaaaa');

    $restored = $this->provider->restore('bx_aaaaaaaa', $checkpoint);

    expect($checkpoint)->toMatch('/^ai-aaaaaaaa-[a-z0-9]{26}$/')
        ->and($restored->id())->toBe('bx_bbbbbbbb');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/named-snapshots'
        && $request['sandboxId'] === 'bx_aaaaaaaa'
        && $request['name'] === $checkpoint);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/sandboxes' && $request['from'] === $checkpoint);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->hasHeader('X-Ascii-Confirm-Delete', 'bx_aaaaaaaa'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'named-snapshots'));
});

test('deleting removes the sandbox and only its own checkpoints, and a missing sandbox counts as deleted', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(['ok' => true], 202),
        'boat.dev/api/v1/sandboxes/bx_aaaaaaaa' => Http::response(['ok' => false], 404),
        'boat.dev/api/v1/sandboxes/bx_cccccccc' => Http::response(['ok' => false, 'message' => 'Forbidden.'], 403),
        'boat.dev/api/v1/named-snapshots' => Http::response(['ok' => true, 'snapshots' => [
            ['name' => 'ai-23456789-01j00000000000000000000000'],
            ['name' => 'ai-aaaaaaaa-01j00000000000000000000000'],
            ['name' => 'my-own-snapshot'],
        ]]),
        'boat.dev/api/v1/named-snapshots/*' => Http::response(['ok' => true]),
    ]);

    $this->provider->delete('bx_23456789');
    $this->provider->delete('bx_aaaaaaaa');

    expect(fn () => $this->provider->delete('bx_cccccccc'))->toThrow(SandboxException::class, 'Forbidden');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), 'named-snapshots/ai-23456789-01j00000000000000000000000'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'my-own-snapshot'));
});
