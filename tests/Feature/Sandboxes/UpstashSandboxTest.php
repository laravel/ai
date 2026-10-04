<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\UpstashProvider;

beforeEach(function () {
    Sleep::fake();

    $this->provider = new UpstashProvider(['name' => 'upstash', 'driver' => 'upstash', 'key' => 'box-key', 'timeout' => 30]);
});

test('a box is created with its options and waited on until it leaves creating', function () {
    Http::fake([
        'us-east-1.box.upstash.com/v2/box' => Http::response(['id' => 'box_abc123', 'status' => 'creating']),
        'us-east-1.box.upstash.com/v2/box/box_abc123' => Http::sequence()
            ->push(['id' => 'box_abc123', 'status' => 'creating'])
            ->whenEmpty(Http::response(['id' => 'box_abc123', 'status' => 'idle'])),
    ]);

    $sandbox = $this->provider->create(['image' => 'node', 'type' => 'medium', 'env' => ['A' => 'b'], 'network' => false]);

    expect($sandbox->id())->toBe('box_abc123')
        ->and($sandbox->cwd())->toBe('/workspace/home');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://us-east-1.box.upstash.com/v2/box'
        && $request->hasHeader('X-Box-Api-Key', 'box-key')
        && $request['runtime'] === 'node'
        && $request['size'] === 'medium'
        && $request['env_vars'] === ['A' => 'b']
        && $request['network_policy'] === ['mode' => 'deny-all']);

    expect(fn () => $this->provider->create(['cpus' => 2]))->toThrow(UnsupportedOptionException::class);
});

test('commands run under a timeout with their environment and directory', function () {
    Http::fake([
        'us-east-1.box.upstash.com/v2/box/box_abc123' => Http::response(['id' => 'box_abc123', 'status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_abc123/exec' => Http::sequence()
            ->push(['exit_code' => 0, 'output' => "hi\n", 'error' => 'warn'])
            ->push(['exit_code' => 124, 'output' => '', 'error' => '']),
    ]);

    $sandbox = $this->provider->get('box_abc123');

    $result = $sandbox->exec('echo $A', env: ['A' => 'b']);

    expect($result->stdout)->toBe("hi\n")
        ->and($result->stderr)->toBe('warn')
        ->and($sandbox->exec('sleep 99', timeout: 5)->timedOut)->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec')
        && $request['command'] === ['timeout', '30', 'sh', '-c', "export A='b'; cd -- '/workspace/home' && echo \$A"]);
});

test('streamed output stops at the exit trailer even when it arrives with the output', function () {
    Http::fake([
        'us-east-1.box.upstash.com/v2/box/box_abc123' => Http::response(['id' => 'box_abc123', 'status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_abc123/exec-stream' => Http::response("one\ntwo\nevent: exit\ndata: {\"exit_code\":2,\"cpu_ns\":5}\n"),
    ]);

    $chunks = '';

    $result = $this->provider->get('box_abc123')->exec('make', onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks .= $chunk;
    });

    expect($chunks)->toBe("one\ntwo\n")
        ->and($result->stdout)->toBe("one\ntwo\n")
        ->and($result->exitCode)->toBe(2);
});

test('files go through the files API, and a missing file is not a missing box', function () {
    Http::fake([
        'us-east-1.box.upstash.com/v2/box/box_abc123' => Http::response(['id' => 'box_abc123', 'status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_abc123/files/stat*' => Http::response(['type' => 'directory', 'size' => 0, 'mod_time' => '2026-10-05T00:00:00Z']),
        'us-east-1.box.upstash.com/v2/box/box_abc123/files/write' => Http::response([]),
        'us-east-1.box.upstash.com/v2/box/box_abc123/files/read*' => Http::sequence()
            ->push(['content' => base64_encode("\x00bin")])
            ->push(['error' => 'not found'], 404),
    ]);

    $sandbox = $this->provider->get('box_abc123');
    $sandbox->write('a.txt', 'hello');

    expect($sandbox->read('a.bin'))->toBe("\x00bin");

    try {
        $sandbox->read('missing');
    } catch (SandboxException $exception) {
        //
    }

    expect($exception ?? null)->toBeInstanceOf(SandboxException::class)->not->toBeInstanceOf(SandboxNotFound::class);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files/write')
        && $request['path'] === '/workspace/home/a.txt'
        && $request['content'] === base64_encode('hello'));
});

test('pause, resume and snapshots map onto the box API, and restore returns a new box', function () {
    Http::fake([
        'us-east-1.box.upstash.com/v2/box/box_old' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response([])
            : Http::response(['id' => 'box_old', 'status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_old/status' => Http::response(['status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_old/pause' => Http::response([]),
        'us-east-1.box.upstash.com/v2/box/box_old/snapshots' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'snap_1', 'status' => 'creating'])
            : Http::response(['snapshots' => [['id' => 'snap_1', 'status' => 'ready']]]),
        'us-east-1.box.upstash.com/v2/box/from-snapshot' => Http::response(['id' => 'box_new', 'status' => 'creating']),
        'us-east-1.box.upstash.com/v2/box/box_new' => Http::response(['id' => 'box_new', 'status' => 'idle']),
        'us-east-1.box.upstash.com/v2/box/box_new/status' => Http::response(['status' => 'idle']),
    ]);

    $this->provider->suspend('box_old');

    expect(fn () => $this->provider->resume('box_old'))->toThrow(SandboxStateException::class);

    $checkpoint = $this->provider->checkpoint('box_old');
    $restored = $this->provider->restore('box_old', $checkpoint);

    expect($checkpoint)->toBe('snap_1')
        ->and($restored->id())->toBe('box_new')
        ->and($restored->state())->toBe(SandboxState::Running);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pause'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/from-snapshot') && $request['snapshot_id'] === 'snap_1');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/v2/box/box_old'));
});
