<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\DaytonaProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\SandboxState;

const DAYTONA_ID = '3f2b8c1e-9a4d-4e6f-8b2a-1c5d7e9f0a3b';

beforeEach(function () {
    Sleep::fake();

    $this->provider = new DaytonaProvider(['name' => 'daytona', 'driver' => 'daytona', 'key' => 'dtn-key', 'timeout' => 30]);
});

function daytonaSandbox(string $state): array
{
    return ['id' => DAYTONA_ID, 'state' => $state, 'toolboxProxyUrl' => 'https://proxy.app.daytona.io/toolbox/'];
}

test('a sandbox is built from an image with its options and waited on until it starts', function () {
    Http::fake([
        'app.daytona.io/api/sandbox' => Http::response(daytonaSandbox('pending_build')),
        'app.daytona.io/api/sandbox/'.DAYTONA_ID => Http::sequence()
            ->push(daytonaSandbox('building_snapshot'))
            ->whenEmpty(Http::response(daytonaSandbox('started'))),
    ]);

    $sandbox = $this->provider->create(['image' => 'node:22', 'env' => ['A' => 'b'], 'cpus' => 2, 'memory' => 4, 'ttl' => 600, 'network' => false]);

    expect($sandbox->id())->toBe(DAYTONA_ID)
        ->and($sandbox->cwd())->toBe('/home/daytona');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://app.daytona.io/api/sandbox'
        && $request->hasHeader('Authorization', 'Bearer dtn-key')
        && $request['buildInfo'] === ['dockerfileContent' => "FROM node:22\n"]
        && ! isset($request['snapshot'])
        && $request['env'] === ['A' => 'b']
        && $request['cpu'] === 2
        && $request['memory'] === 4
        && $request['autoStopInterval'] === 10
        && $request['networkBlockAll'] === true);
});

test('a failed build is reported instead of waited on', function () {
    Http::fake([
        'app.daytona.io/api/sandbox' => Http::response(daytonaSandbox('pending_build')),
        'app.daytona.io/api/sandbox/'.DAYTONA_ID => Http::response([...daytonaSandbox('build_failed'), 'errorReason' => 'no such image']),
    ]);

    expect(fn () => $this->provider->create(['image' => 'nope']))->toThrow(SandboxException::class, 'no such image');
});

test('commands run through the toolbox, report timeouts, and refuse to stream', function () {
    Http::fake([
        'app.daytona.io/api/sandbox/'.DAYTONA_ID => Http::response(daytonaSandbox('started')),
        'proxy.app.daytona.io/toolbox/'.DAYTONA_ID.'/process/execute' => Http::sequence()
            ->push(['exitCode' => 2, 'result' => "out and err\n"])
            ->push(['message' => 'timeout'], 408),
    ]);

    $sandbox = $this->provider->get(DAYTONA_ID);

    $result = $sandbox->exec('make', env: ['A' => 'b']);

    expect($result->stdout)->toBe("out and err\n")
        ->and($result->exitCode)->toBe(2)
        ->and($sandbox->exec('sleep 99')->timedOut)->toBeTrue()
        ->and(fn () => $sandbox->exec('make', onOutput: fn () => null))->toThrow(SandboxException::class, 'cannot stream');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/process/execute')
        && $request['command'] === 'make'
        && $request['cwd'] === '/home/daytona'
        && (array) $request['envs'] === ['A' => 'b']
        && $request['timeout'] === 30);
});

test('files upload raw and download raw', function () {
    Http::fake([
        'app.daytona.io/api/sandbox/'.DAYTONA_ID => Http::response(daytonaSandbox('started')),
        'proxy.app.daytona.io/toolbox/'.DAYTONA_ID.'/process/execute' => Http::response(['exitCode' => 0, 'result' => "directory|0|0\n"]),
        'proxy.app.daytona.io/toolbox/'.DAYTONA_ID.'/files/upload-v2*' => Http::response([]),
        'proxy.app.daytona.io/toolbox/'.DAYTONA_ID.'/files/download*' => Http::response("\x00bin"),
    ]);

    $sandbox = $this->provider->get(DAYTONA_ID);
    $sandbox->write('notes.txt', 'hello');

    expect($sandbox->read('a.bin'))->toBe("\x00bin");

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'files/upload-v2?path=%2Fhome%2Fdaytona%2Fnotes.txt') && $request->body() === 'hello');
});

test('missing sandboxes, stop and start map onto the API', function () {
    Http::fake([
        'app.daytona.io/api/sandbox/'.DAYTONA_ID => Http::sequence()
            ->push(daytonaSandbox('started'))
            ->push(daytonaSandbox('started'))
            ->push(daytonaSandbox('started'))
            ->push(daytonaSandbox('stopped'))
            ->push(daytonaSandbox('stopped'))
            ->whenEmpty(Http::response(daytonaSandbox('started'))),
        'app.daytona.io/api/sandbox/'.DAYTONA_ID.'/*' => Http::response(daytonaSandbox('started')),
        'app.daytona.io/api/sandbox/00000000-0000-4000-8000-000000000000' => Http::response(['statusCode' => 404, 'message' => 'Sandbox not found'], 404),
    ]);

    expect(fn () => $this->provider->get('00000000-0000-4000-8000-000000000000'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->resume(DAYTONA_ID))->toThrow(SandboxStateException::class);

    $this->provider->suspend(DAYTONA_ID);
    $this->provider->resume(DAYTONA_ID);

    expect($this->provider->get(DAYTONA_ID)->state())->toBe(SandboxState::Running);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/stop'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/start'));
});
