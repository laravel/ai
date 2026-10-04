<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Ai;
use Laravel\Ai\Sandboxes\BoatFactory;
use Laravel\Ai\Sandboxes\Exceptions\SandboxDied;

beforeEach(function () {
    Sleep::fake();

    $this->factory = new BoatFactory(['key' => 'boat-key', 'timeout' => 30]);
});

function boatSandbox(string $id, string $state): array
{
    return ['ok' => true, 'type' => 'sandbox.info', 'sandbox' => ['id' => $id, 'name' => 'Sandbox', 'state' => $state, 'desktopAvailable' => false, 'snapshotAvailable' => false]];
}

test('a sandbox is provisioned once, waited on until ready, and reused on the next turn', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['ok' => true, 'type' => 'sandbox.created', 'sandbox' => ['id' => 'bx_23456789', 'state' => 'provisioning']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::sequence()
            ->push(boatSandbox('bx_23456789', 'provisioning'))
            ->whenEmpty(Http::response(boatSandbox('bx_23456789', 'idle'))),
        'boat.dev/api/v1/sandboxes/bx_23456789/commands' => Http::response(['ok' => true, 'type' => 'command.finished', 'success' => true, 'exitCode' => 0, 'stdout' => "hi\n", 'stderr' => '', 'timedOut' => false]),
    ]);

    $sandbox = $this->factory->create('conversation-1');

    $result = $sandbox->exec('echo $GREETING', env: ['GREETING' => "it's"]);

    $this->factory->create('conversation-1');

    expect($result->stdout)->toBe("hi\n")
        ->and($sandbox->cwd())->toBe('/home/user')
        ->and($this->factory->boat('conversation-1'))->toBe('bx_23456789');

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/sandboxes'
        && $request->hasHeader('Authorization', 'Bearer boat-key')
        && $request['noEnv'] === true
        && $request['type'] === 'small'
        && $request['ttlSeconds'] === 900);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/commands')
        && $request['command'] === "export GREETING='it'\\''s'; cd -- '/home/user' && echo \$GREETING"
        && $request['timeoutSeconds'] === 30);
});

test('an archived sandbox is resumed before it is used', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['sandbox' => ['id' => 'bx_23456789', 'state' => 'archived']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789/resume' => Http::response(['ok' => true, 'type' => 'sandbox.resuming'], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'ready')),
    ]);

    $this->factory->create('conversation-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/resume'));
});

test('files are read and written through the file API as base64', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['sandbox' => ['id' => 'bx_23456789', 'state' => 'ready']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'ready')),
        'boat.dev/api/v1/sandboxes/bx_23456789/files*' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response(['ok' => true, 'type' => 'file.written'])
            : Http::response(['ok' => true, 'type' => 'file.read', 'content' => base64_encode("\x00binary")]),
    ]);

    $sandbox = $this->factory->create('conversation-1');

    $sandbox->write('notes.txt', "line\n");

    expect($sandbox->read('image.bin'))->toBe("\x00binary");

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['path'] === '/home/user/notes.txt'
        && $request['content'] === base64_encode("line\n")
        && $request['encoding'] === 'base64');
});

test('a stopped sandbox surfaces as a dead sandbox', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['sandbox' => ['id' => 'bx_23456789', 'state' => 'ready']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => Http::response(boatSandbox('bx_23456789', 'ready')),
        'boat.dev/api/v1/sandboxes/bx_23456789/commands' => Http::response(['ok' => false, 'status' => 409, 'code' => 'sandbox_not_ready', 'message' => 'Sandbox is stopped.'], 409),
    ]);

    expect(fn () => $this->factory->create('conversation-1')->exec('true'))->toThrow(SandboxDied::class, 'Sandbox is stopped.');
});

test('restoring a checkpoint moves the conversation to a sandbox started from the snapshot', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::sequence()
            ->push(['sandbox' => ['id' => 'bx_aaaaaaaa', 'state' => 'ready']], 202)
            ->push(['sandbox' => ['id' => 'bx_bbbbbbbb', 'state' => 'provisioning']], 202),
        'boat.dev/api/v1/sandboxes/bx_aaaaaaaa' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(['ok' => true], 202)
            : Http::response(boatSandbox('bx_aaaaaaaa', 'ready')),
        'boat.dev/api/v1/sandboxes/bx_bbbbbbbb' => Http::response(boatSandbox('bx_bbbbbbbb', 'ready')),
        'boat.dev/api/v1/named-snapshots' => Http::response(['ok' => true, 'snapshot' => ['status' => 'saving']], 202),
        'boat.dev/api/v1/named-snapshots/*' => Http::sequence()
            ->push(['snapshot' => ['status' => 'saving']])
            ->whenEmpty(Http::response(['snapshot' => ['status' => 'ready']])),
    ]);

    $this->factory->create('conversation-1');

    $checkpoint = $this->factory->checkpoint('conversation-1');

    $this->factory->restore('conversation-1', $checkpoint);

    expect($checkpoint)->toMatch('/^ai-[a-f0-9]{12}-[a-z0-9]{26}$/')
        ->and($this->factory->boat('conversation-1'))->toBe('bx_bbbbbbbb');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/named-snapshots'
        && $request['sandboxId'] === 'bx_aaaaaaaa'
        && $request['name'] === $checkpoint);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://boat.dev/api/v1/sandboxes'
        && ($request['from'] ?? null) === $checkpoint);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/sandboxes/bx_aaaaaaaa')
        && $request->hasHeader('X-Ascii-Confirm-Delete', 'bx_aaaaaaaa'));
});

test('forgetting a sandbox deletes it and only its own snapshots', function () {
    Http::fake([
        'boat.dev/api/v1/sandboxes' => Http::response(['sandbox' => ['id' => 'bx_23456789', 'state' => 'ready']], 202),
        'boat.dev/api/v1/sandboxes/bx_23456789' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(['ok' => true], 202)
            : Http::response(boatSandbox('bx_23456789', 'ready')),
        'boat.dev/api/v1/named-snapshots' => fn () => Http::response(['ok' => true, 'snapshots' => [
            ['name' => 'ai-'.substr(hash('xxh128', 'conversation-1'), 0, 12).'-01j00000000000000000000000'],
            ['name' => 'ai-'.substr(hash('xxh128', 'conversation-2'), 0, 12).'-01j00000000000000000000000'],
            ['name' => 'my-own-snapshot'],
        ]]),
        'boat.dev/api/v1/named-snapshots/*' => Http::response(['ok' => true]),
    ]);

    $this->factory->create('conversation-1');
    $this->factory->forget('conversation-1');

    expect($this->factory->boat('conversation-1'))->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sandboxes/bx_23456789'));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'named-snapshots/ai-'.substr(hash('xxh128', 'conversation-1'), 0, 12)));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), substr(hash('xxh128', 'conversation-2'), 0, 12)));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'my-own-snapshot'));
});

test('the boat driver registers through the manager', function () {
    expect(Ai::sandbox('boat'))->toBeInstanceOf(BoatFactory::class);
});
