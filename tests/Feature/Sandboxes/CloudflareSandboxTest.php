<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Sandboxes\CloudflareProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;

beforeEach(function () {
    $this->provider = new CloudflareProvider(['name' => 'cloudflare', 'driver' => 'cloudflare', 'url' => 'https://bridge.example.workers.dev', 'key' => 'bridge-key', 'timeout' => 30]);
});

function sse(string $event, string $data): string
{
    return "event: {$event}\ndata: {$data}\n\n";
}

test('sandboxes are created and deleted through the bridge, which takes no per-sandbox options', function () {
    Http::fake([
        'bridge.example.workers.dev/v1/sandbox' => Http::response(['id' => 'abcdefgh234567']),
        'bridge.example.workers.dev/v1/sandbox/abcdefgh234567' => Http::response('', 204),
    ]);

    $sandbox = $this->provider->create();
    $this->provider->delete($sandbox->id());

    expect($sandbox->id())->toBe('abcdefgh234567')
        ->and($sandbox->cwd())->toBe('/workspace')
        ->and(fn () => $this->provider->create(['image' => 'node:22']))->toThrow(UnsupportedOptionException::class)
        ->and(fn () => $this->provider->get('Not-Valid!'))->toThrow(SandboxNotFound::class)
        ->and(fn () => (new CloudflareProvider(['driver' => 'cloudflare']))->create())->toThrow(SandboxException::class, 'bridge Worker');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->hasHeader('Authorization', 'Bearer bridge-key'));
});

test('command output arrives as base64 server-sent events', function () {
    Http::fake([
        'bridge.example.workers.dev/v1/sandbox/abcdefgh234567/exec' => Http::response(
            sse('stdout', base64_encode("one\n")).sse('stderr', base64_encode("two\n")).sse('exit', '{"exit_code":3}'),
        ),
    ]);

    $chunks = [];

    $result = $this->provider->get('abcdefgh234567')->exec('make', env: ['A' => 'b'], onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[] = "{$type}:{$chunk}";
    });

    expect($chunks)->toBe(["stdout:one\n", "stderr:two\n"])
        ->and($result->stdout)->toBe("one\n")
        ->and($result->exitCode)->toBe(3);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/exec')
        && $request['argv'] === ['timeout', '30', 'sh', '-c', "export A='b'; cd -- '/workspace' && make"]
        && $request['timeout_ms'] === 35000);
});

test('a bridge error event becomes an exception', function () {
    Http::fake([
        'bridge.example.workers.dev/v1/sandbox/abcdefgh234567/exec' => Http::response(sse('error', '{"error":"container crashed","code":"exec_error"}')),
    ]);

    expect(fn () => $this->provider->get('abcdefgh234567')->exec('make'))->toThrow(SandboxException::class, 'container crashed');
});

test('files are read and written as raw bytes at their workspace path', function () {
    Http::fake([
        'bridge.example.workers.dev/v1/sandbox/abcdefgh234567/exec' => Http::response(sse('stdout', base64_encode("directory|0|0\n")).sse('exit', '{"exit_code":0}')),
        'bridge.example.workers.dev/v1/sandbox/abcdefgh234567/file/*' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response(['ok' => true])
            : Http::response("\x00bin"),
    ]);

    $sandbox = $this->provider->get('abcdefgh234567');
    $sandbox->write('a b.txt', 'hello');

    expect($sandbox->read('a.bin'))->toBe("\x00bin");

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/file/workspace/a%20b.txt')
        && $request->body() === 'hello');
});
