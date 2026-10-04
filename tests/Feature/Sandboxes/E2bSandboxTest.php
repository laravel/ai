<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Sandboxes\E2bProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\SandboxState;

beforeEach(function () {
    $this->provider = new E2bProvider(['name' => 'e2b', 'driver' => 'e2b', 'key' => 'e2b-key', 'timeout' => 30]);
});

function connectFrame(array $message, int $flags = 0): string
{
    $json = json_encode($message);

    return pack('CN', $flags, strlen($json)).$json;
}

function e2bSandbox(string $state = 'running'): array
{
    return ['sandboxID' => 'abc123', 'envdAccessToken' => 'token-1', 'domain' => 'e2b.app', 'state' => $state];
}

test('a sandbox is created from a template that pauses rather than dies when it times out', function () {
    Http::fake(['api.e2b.app/v2/sandboxes' => Http::response(e2bSandbox(), 201)]);

    $sandbox = $this->provider->create(['image' => 'node', 'env' => ['A' => 'b'], 'ttl' => 600, 'network' => false]);

    expect($sandbox->id())->toBe('abc123')
        ->and($sandbox->cwd())->toBe('/home/user');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.e2b.app/v2/sandboxes'
        && $request->hasHeader('X-API-Key', 'e2b-key')
        && $request['templateID'] === 'node'
        && $request['timeout'] === 600
        && $request['envVars'] === ['A' => 'b']
        && $request['allow_internet_access'] === false
        && $request['autoPause'] === true);
});

test('commands run through the daemon as Connect frames, with output streamed and the exit code read', function () {
    Http::fake([
        'api.e2b.app/sandboxes/abc123' => Http::response(e2bSandbox()),
        '49983-abc123.e2b.app/process.Process/Start' => Http::response(implode('', [
            connectFrame(['event' => ['start' => ['pid' => 7]]]),
            connectFrame(['event' => ['data' => ['stdout' => base64_encode("one\n")]]]),
            connectFrame(['event' => ['data' => ['stderr' => base64_encode("two\n")]]]),
            connectFrame(['event' => ['end' => ['exitCode' => 3, 'exited' => true]]]),
            connectFrame([], 0x02),
        ])),
    ]);

    $chunks = [];

    $result = $this->provider->get('abc123')->exec('make', env: ['A' => 'b'], onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[] = "{$type}:{$chunk}";
    });

    expect($chunks)->toBe(["stdout:one\n", "stderr:two\n"])
        ->and($result->stdout)->toBe("one\n")
        ->and($result->stderr)->toBe("two\n")
        ->and($result->exitCode)->toBe(3);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), 'process.Process/Start')) {
            return false;
        }

        $message = json_decode(substr($request->body(), 5), true);

        return $request->hasHeader('X-Access-Token', 'token-1')
            && $request->hasHeader('Connect-Timeout-Ms', '30000')
            && unpack('N', substr($request->body(), 1, 4))[1] === strlen($request->body()) - 5
            && $message['process']['args'] === ['-l', '-c', 'make']
            && $message['process']['envs'] === ['A' => 'b']
            && $message['process']['cwd'] === '/home/user';
    });
});

test('a command past its deadline comes back timed out with its output so far', function () {
    Http::fake([
        'api.e2b.app/sandboxes/abc123' => Http::response(e2bSandbox()),
        '49983-abc123.e2b.app/process.Process/Start' => Http::response(
            connectFrame(['event' => ['data' => ['stdout' => base64_encode('started')]]]).connectFrame(['error' => ['code' => 'deadline_exceeded', 'message' => 'deadline']], 0x02),
        ),
    ]);

    $result = $this->provider->get('abc123')->exec('sleep 99', timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->stdout)->toBe('started');
});

test('a rotated access token is refreshed once and the request retried', function () {
    Http::fake([
        'api.e2b.app/sandboxes/abc123' => Http::sequence()
            ->push(e2bSandbox())
            ->push([...e2bSandbox(), 'envdAccessToken' => 'token-2']),
        '49983-abc123.e2b.app/files*' => fn (Request $request) => $request->hasHeader('X-Access-Token', 'token-2')
            ? Http::response("\x00bin")
            : Http::response('', 401),
    ]);

    expect($this->provider->get('abc123')->read('a.bin'))->toBe("\x00bin");
});

test('missing sandboxes, pause and resume map onto the API', function () {
    Http::fake([
        'api.e2b.app/sandboxes/abc123' => Http::sequence()
            ->push(e2bSandbox('running'))
            ->push(e2bSandbox('running'))
            ->whenEmpty(Http::response(e2bSandbox('paused'))),
        'api.e2b.app/sandboxes/abc123/pause' => Http::response('', 204),
        'api.e2b.app/v2/sandboxes/abc123/connect' => Http::response(e2bSandbox(), 201),
        'api.e2b.app/sandboxes/gone99' => Http::response(['code' => 404, 'message' => 'not found'], 404),
    ]);

    expect(fn () => $this->provider->get('gone99'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->resume('abc123'))->toThrow(SandboxStateException::class);

    $this->provider->suspend('abc123');

    expect($this->provider->get('abc123')->state())->toBe(SandboxState::Stopped);

    $this->provider->resume('abc123');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/connect'));
});
