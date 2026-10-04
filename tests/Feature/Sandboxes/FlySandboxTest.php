<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\FlyProvider;
use Laravel\Ai\Sandboxes\SandboxState;

beforeEach(function () {
    Sleep::fake();

    $this->provider = new FlyProvider(['name' => 'fly', 'driver' => 'fly', 'key' => 'fly-token', 'app' => 'my-sandboxes', 'timeout' => 30]);
});

test('a machine is created to idle without restarting, waited on, and given its working directory', function () {
    Http::fake([
        'api.machines.dev/v1/apps/my-sandboxes/machines' => Http::response(['id' => '1857156b526dd8', 'state' => 'created']),
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8/wait*' => Http::response(['ok' => true, 'state' => 'started']),
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8/exec' => Http::response(['stdout' => '', 'stderr' => '', 'exit_code' => 0]),
    ]);

    $sandbox = $this->provider->create(['image' => 'node:22', 'env' => ['A' => 'b'], 'cpus' => 2, 'memory' => 2048]);

    expect($sandbox->id())->toBe('1857156b526dd8')
        ->and($sandbox->cwd())->toBe('/workspace');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/machines')
        && $request->hasHeader('Authorization', 'Bearer fly-token')
        && $request['config']['image'] === 'node:22'
        && (array) $request['config']['env'] === ['A' => 'b']
        && $request['config']['guest'] === ['cpu_kind' => 'shared', 'cpus' => 2, 'memory_mb' => 2048]
        && $request['config']['init'] === ['cmd' => ['sleep', 'infinity']]
        && $request['config']['restart'] === ['policy' => 'no']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec') && $request['command'] === ['mkdir', '-p', '--', '/workspace']);

    expect(fn () => $this->provider->create(['network' => false]))->toThrow(UnsupportedOptionException::class);
});

test('commands run through exec under a timeout, and files travel as base64 through stdin and stdout', function () {
    Http::fake([
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8' => Http::response(['id' => '1857156b526dd8', 'state' => 'started']),
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8/exec' => function (Request $request) {
            return match ($request['command'][0]) {
                'timeout' => Http::response(['stdout' => "hi\n", 'stderr' => 'warn', 'exit_code' => 124]),
                'base64' => Http::response(['stdout' => base64_encode("\x00bin")."\n"]),
                default => Http::response(['stdout' => '', 'stderr' => '']),
            };
        },
    ]);

    $sandbox = $this->provider->get('1857156b526dd8');

    $result = $sandbox->exec('echo $A', env: ['A' => 'b']);
    $sandbox->driver()->write('/workspace/a.bin', "\x00bin");

    expect($result->stdout)->toBe("hi\n")
        ->and($result->timedOut)->toBeTrue()
        ->and($sandbox->read('a.bin'))->toBe("\x00bin")
        ->and(fn () => $sandbox->exec('make', onOutput: fn () => null))->toThrow(SandboxException::class, 'cannot stream');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec')
        && $request['command'] === ['timeout', '30', 'sh', '-c', "export A='b'; cd -- '/workspace' && echo \$A"]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec')
        && $request['command'] === ['sh', '-c', 'base64 -d > "$1"', 'sh', '/workspace/a.bin']
        && $request['stdin'] === base64_encode("\x00bin"));
});

test('missing and destroyed machines are not found, and suspend and resume map onto the API', function () {
    Http::fake([
        'api.machines.dev/v1/apps/my-sandboxes/machines/aaaaaaaaaaaaaa' => Http::response(['error' => 'machine not found'], 404),
        'api.machines.dev/v1/apps/my-sandboxes/machines/bbbbbbbbbbbbbb' => Http::response(['id' => 'bbbbbbbbbbbbbb', 'state' => 'destroyed']),
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8' => Http::sequence()
            ->push(['state' => 'started'])
            ->whenEmpty(Http::response(['state' => 'suspended'])),
        'api.machines.dev/v1/apps/my-sandboxes/machines/1857156b526dd8/*' => Http::response(['ok' => true]),
    ]);

    expect(fn () => $this->provider->get('aaaaaaaaaaaaaa'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->get('bbbbbbbbbbbbbb'))->toThrow(SandboxNotFound::class);

    $this->provider->suspend('1857156b526dd8');

    expect($this->provider->get('1857156b526dd8')->state())->toBe(SandboxState::Stopped);

    $this->provider->resume('1857156b526dd8');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/suspend'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/start'));
});
