<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Sandboxes\DockerProvider;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\SandboxState;

beforeEach(function () {
    if (! Process::run(['docker', 'info'])->successful()) {
        $this->markTestSkipped('Docker is not available.');
    }

    $this->config = [
        'name' => 'docker',
        'driver' => 'docker',
        'image' => env('AI_SANDBOX_TEST_IMAGE', 'alpine:3.20'),
        'workdir' => '/workspace',
        'memory' => '256m',
        'cpus' => '1',
        'network' => false,
        'timeout' => 30,
    ];

    $this->provider = new DockerProvider($this->config);
    $this->sandboxes = [];

    $this->create = function (array $options = []) {
        $sandbox = $this->provider->create($options);

        $this->sandboxes[] = $sandbox->id();

        return $sandbox;
    };
});

afterEach(function () {
    foreach ($this->sandboxes ?? [] as $id) {
        $this->provider->delete($id);
    }
});

test('commands run inside the container, isolated from the host and the network', function () {
    $sandbox = ($this->create)(['env' => ['GREETING' => 'hi']]);

    $result = $sandbox->exec('pwd; ls /sys/class/net; echo $GREETING; test -e /Users && echo host-visible; true');

    expect($result->stdout)->toBe("/workspace\nlo\nhi\n")
        ->and($result->successful())->toBeTrue()
        ->and($sandbox->state())->toBe(SandboxState::Running);
});

test('a fresh provider attaches to the container by ID without starting or replacing it', function () {
    $sandbox = ($this->create)();
    $sandbox->write('kept.txt', 'still here');

    Process::run(['docker', 'stop', '-t', '0', $this->provider->container($sandbox->id())]);

    $attached = (new DockerProvider($this->config))->get($sandbox->id());

    expect($attached->state())->toBe(SandboxState::Stopped)
        ->and(fn () => $attached->exec('true'))->toThrow(SandboxStateException::class)
        ->and(fn () => $this->provider->get('01jzzzzzzzzzzzzzzzzzzzzzzz'))->toThrow(SandboxNotFound::class);

    $this->provider->resume($sandbox->id());

    expect($attached->read('kept.txt'))->toBe('still here')
        ->and(fn () => $this->provider->resume($sandbox->id()))->toThrow(SandboxStateException::class);
});

test('files round trip through the container, including binary contents and awkward names', function () {
    $sandbox = ($this->create)();

    $sandbox->write('src/a b/it\'s.bin', "line one\n\$HOME `x`\x00\xff");

    expect($sandbox->read('src/a b/it\'s.bin'))->toBe("line one\n\$HOME `x`\x00\xff")
        ->and($sandbox->stat('src/a b/it\'s.bin'))->isFile->toBeTrue()
        ->and($sandbox->stat('src'))->isDirectory->toBeTrue()
        ->and($sandbox->stat('missing'))->toBeNull()
        ->and($sandbox->readdir('src'))->toBe(['a b']);

    $sandbox->rm('src', recursive: true);

    expect($sandbox->exists('src'))->toBeFalse();
});

test('command output streams from the container', function () {
    $chunks = [];

    $result = ($this->create)()->exec('echo one; echo two >&2', onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[$type] = ($chunks[$type] ?? '').$chunk;
    });

    expect($chunks)->toBe(['stdout' => "one\n", 'stderr' => "two\n"])
        ->and($result->stdout)->toBe("one\n");
});

test('a command past its timeout is stopped inside the container', function () {
    $sandbox = ($this->create)();

    $result = $sandbox->exec('echo started; sleep 30', timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->stdout)->toBe("started\n")
        ->and($sandbox->exec('ps -o args | grep -c "[s]leep 30"')->stdout)->toBe("0\n");
});

test('options the container cannot honor are refused before anything starts', function () {
    $before = Process::run(['docker', 'ps', '-aq', '--filter', 'label='.DockerProvider::LABEL])->output();

    expect(fn () => $this->provider->create(['ttl' => 60]))->toThrow(UnsupportedOptionException::class)
        ->and(fn () => $this->provider->create(['network' => ['github.com:443']]))->toThrow(UnsupportedOptionException::class)
        ->and(Process::run(['docker', 'ps', '-aq', '--filter', 'label='.DockerProvider::LABEL])->output())->toBe($before);
});

test('deleting removes the container and its volume, repeats safely, and fails later calls as missing', function () {
    $sandbox = $this->provider->create();

    $this->provider->delete($sandbox->id());
    $this->provider->delete($sandbox->id());

    expect(Process::run(['docker', 'volume', 'inspect', $this->provider->container($sandbox->id())])->successful())->toBeFalse()
        ->and($sandbox->state())->toBe(SandboxState::Terminated)
        ->and(fn () => $sandbox->exec('true'))->toThrow(SandboxNotFound::class);
});

test('restoring a checkpoint brings back the workspace and the container filesystem with the same options', function () {
    $sandbox = ($this->create)(['env' => ['KEPT' => 'yes']]);

    $sandbox->write('notes.txt', 'v1');
    $sandbox->exec('touch /etc/installed-by-agent');

    $checkpoint = $this->provider->checkpoint($sandbox->id());

    $sandbox->write('notes.txt', 'v2');
    $sandbox->write('later.txt', 'later');
    $sandbox->exec('rm /etc/installed-by-agent');

    $restored = $this->provider->restore($sandbox->id(), $checkpoint);

    expect($restored->id())->toBe($sandbox->id())
        ->and($restored->read('notes.txt'))->toBe('v1')
        ->and($restored->exists('later.txt'))->toBeFalse()
        ->and($restored->exec('test -f /etc/installed-by-agent && echo $KEPT')->stdout)->toBe("yes\n");
});

test('a checkpoint without its workspace copy is refused before the workspace is touched', function () {
    $sandbox = ($this->create)();
    $sandbox->write('notes.txt', 'v1');

    $checkpoint = $this->provider->checkpoint($sandbox->id());

    Process::run(['docker', 'volume', 'rm', '-f', $this->provider->container($sandbox->id()).'-'.$checkpoint]);

    expect(fn () => $this->provider->restore($sandbox->id(), $checkpoint))->toThrow(RuntimeException::class)
        ->and($this->provider->get($sandbox->id())->read('notes.txt'))->toBe('v1');
});

test('checkpoints are removed one at a time or with the sandbox', function () {
    $sandbox = $this->provider->create();
    $sandbox->write('a.txt', 'a');

    $first = $this->provider->checkpoint($sandbox->id());
    $second = $this->provider->checkpoint($sandbox->id());

    $this->provider->forgetCheckpoint($sandbox->id(), $first);

    $image = fn ($checkpoint) => Process::run(['docker', 'image', 'inspect', $this->provider->container($sandbox->id()).':'.$checkpoint])->successful();

    expect($image($first))->toBeFalse()
        ->and($image($second))->toBeTrue();

    $this->provider->delete($sandbox->id());

    expect($image($second))->toBeFalse()
        ->and(Process::run(['docker', 'volume', 'inspect', $this->provider->container($sandbox->id()).'-'.$second])->successful())->toBeFalse();
});
