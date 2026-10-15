<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Sandboxes\BoxLiteProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;

beforeEach(function () {
    Sleep::fake();

    $this->provider = new BoxLiteProvider(['name' => 'boxlite', 'driver' => 'boxlite', 'key' => 'bl-key', 'timeout' => 30]);
});

function tarOf(array $files): string
{
    $path = sys_get_temp_dir().'/boxlite-test-'.uniqid().'.tar';

    $tar = new PharData($path);

    foreach ($files as $name => $contents) {
        $tar->addFromString($name, $contents);
    }

    $bytes = file_get_contents($path);

    unlink($path);

    return $bytes;
}

test('a box is created with its options, started, and given its working directory', function () {
    Http::fake([
        'localhost:8100/v1/boxes' => Http::response(['box_id' => '01JBOX', 'status' => 'configured'], 201),
        'localhost:8100/v1/boxes/01JBOX/start' => Http::response(['box_id' => '01JBOX', 'status' => 'running']),
        'localhost:8100/v1/boxes/01JBOX/exec' => Http::response(['execution_id' => 'exec1'], 201),
        'localhost:8100/v1/boxes/01JBOX/executions/exec1' => Http::response(['execution_id' => 'exec1', 'status' => 'completed', 'exit_code' => 0]),
        'localhost:8100/v1/boxes/01JBOX/files*' => Http::response(tarOf(['out' => '', 'err' => ''])),
    ]);

    $sandbox = $this->provider->create(['image' => 'node:22', 'env' => ['A' => 'b'], 'cpus' => 2, 'memory' => 2048, 'ttl' => 600]);

    expect($sandbox->id())->toBe('01JBOX')
        ->and($sandbox->cwd())->toBe('/workspace');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8100/v1/boxes'
        && $request->hasHeader('Authorization', 'Bearer bl-key')
        && $request['image'] === 'node:22'
        && $request['cpus'] === 2
        && $request['memory_mib'] === 2048
        && $request['env'] === ['A' => 'b']
        && $request['auto_stop'] === 600);
});

test('commands capture their output in files that are read back once the execution finishes', function () {
    Http::fake([
        'localhost:8100/v1/boxes/01JBOX' => Http::response(['box_id' => '01JBOX', 'status' => 'running']),
        'localhost:8100/v1/boxes/01JBOX/exec' => Http::response(['execution_id' => 'exec1'], 201),
        'localhost:8100/v1/boxes/01JBOX/executions/exec1' => Http::sequence()
            ->push(['execution_id' => 'exec1', 'status' => 'running'])
            ->push(['execution_id' => 'exec1', 'status' => 'completed', 'exit_code' => 3]),
        'localhost:8100/v1/boxes/01JBOX/files*' => Http::response(tarOf(['out' => "one\n", 'err' => "two\n"])),
    ]);

    $sandbox = $this->provider->get('01JBOX');

    $result = $sandbox->exec('make', env: ['A' => 'b']);

    expect($result->stdout)->toBe("one\n")
        ->and($result->stderr)->toBe("two\n")
        ->and($result->exitCode)->toBe(3)
        ->and(fn () => $sandbox->exec('make', onOutput: fn () => null))->toThrow(SandboxException::class, 'cannot stream');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec')
        && $request['command'] === 'sh'
        && str_contains($request['args'][1], "export A='b'; cd -- '/workspace' && make")
        && $request['timeout_seconds'] === 30);
});

test('files travel as tar archives', function () {
    Http::fake([
        'localhost:8100/v1/boxes/01JBOX' => Http::response(['box_id' => '01JBOX', 'status' => 'running']),
        'localhost:8100/v1/boxes/01JBOX/files*' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response('', 204)
            : Http::response(tarOf(['a.bin' => "\x00bin"])),
    ]);

    $sandbox = $this->provider->get('01JBOX');
    $sandbox->driver()->write('/workspace/notes.txt', 'hello');

    expect($sandbox->read('a.bin'))->toBe("\x00bin");

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $path = sys_get_temp_dir().'/boxlite-sent-'.uniqid().'.tar';
        file_put_contents($path, $request->body());
        $contents = file_get_contents('phar://'.$path.'/notes.txt');
        unlink($path);

        return str_contains($request->url(), 'path=%2Fworkspace') && $contents === 'hello';
    });
});

test('checkpoints stop a running box, snapshot it, and start it again', function () {
    Http::fake([
        'localhost:8100/v1/boxes/01JBOX' => Http::response(['box_id' => '01JBOX', 'status' => 'running']),
        'localhost:8100/v1/boxes/01JBOX/*' => Http::response(['box_id' => '01JBOX', 'status' => 'running']),
        'localhost:8100/v1/boxes/missing' => Http::response(['error' => ['message' => 'not found']], 404),
    ]);

    $checkpoint = $this->provider->checkpoint('01JBOX');
    $this->provider->restore('01JBOX', $checkpoint);

    $calls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.str_replace('http://localhost:8100/v1/boxes/01JBOX', '', $pair[0]->url()))
        ->filter(fn ($call) => str_starts_with($call, 'POST'))->values()->all();

    expect($checkpoint)->toStartWith('laravel-ai-')
        ->and($calls)->toBe([
            'POST /stop', 'POST /snapshots', 'POST /start',
            'POST /stop', "POST /snapshots/{$checkpoint}/restore", 'POST /start',
        ])
        ->and(fn () => $this->provider->get('missing'))->toThrow(SandboxNotFound::class);
});
