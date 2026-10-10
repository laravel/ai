<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\LocalProvider;
use Laravel\Ai\Sandboxes\SandboxState;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-sandboxes-'.uniqid();
    $this->provider = new LocalProvider(['name' => 'local', 'driver' => 'local', 'workdir' => $this->root, 'timeout' => 5, 'isolate' => false]);
    $this->sandbox = $this->provider->create();
    $this->workspace = "{$this->root}/{$this->sandbox->id()}";
});

afterEach(fn () => File::deleteDirectory($this->root));

test('each create makes a new workspace that a later request can attach to by ID', function () {
    $this->sandbox->write('notes.txt', 'kept');

    $other = $this->provider->create();
    $attached = (new LocalProvider(['name' => 'local', 'driver' => 'local', 'workdir' => $this->root, 'isolate' => false]))->get($this->sandbox->id());

    expect($other->id())->not->toBe($this->sandbox->id())
        ->and($other->exists('notes.txt'))->toBeFalse()
        ->and($attached->read('notes.txt'))->toBe('kept')
        ->and($attached->state())->toBe(SandboxState::Running)
        ->and($attached->provider())->toBe('local');
});

test('attaching never creates a workspace', function () {
    expect(fn () => $this->provider->get('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->get('../etc'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->get("{$this->root}/missing"))->toThrow(SandboxNotFound::class)
        ->and(File::exists("{$this->root}/missing"))->toBeFalse();
});

test('an existing directory can be attached by path and is never deleted or checkpointed', function () {
    File::ensureDirectoryExists($app = "{$this->root}/app");
    File::put("{$app}/composer.json", '{}');

    $sandbox = $this->provider->get($app);

    expect($sandbox->id())->toBe(realpath($app))
        ->and($sandbox->read('composer.json'))->toBe('{}')
        ->and(fn () => $this->provider->delete($app))->toThrow(SandboxException::class)
        ->and(fn () => $this->provider->checkpoint($app))->toThrow(SandboxException::class)
        ->and(File::exists("{$app}/composer.json"))->toBeTrue();
});

test('deleting removes the workspace, repeats safely, and leaves later calls failing as missing', function () {
    $this->provider->delete($this->sandbox->id());
    $this->provider->delete($this->sandbox->id());

    expect(File::exists($this->workspace))->toBeFalse()
        ->and($this->sandbox->state())->toBe(SandboxState::Terminated)
        ->and(fn () => $this->sandbox->exec('true'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->sandbox->read('a.txt'))->toThrow(SandboxNotFound::class)
        ->and(fn () => $this->provider->get($this->sandbox->id()))->toThrow(SandboxNotFound::class);
});

test('create options are validated before anything is made and kept for later attachments', function () {
    expect(fn () => $this->provider->create(['image' => 'node:22']))->toThrow(UnsupportedOptionException::class, '[image]')
        ->and(fn () => $this->provider->create(['network' => ['github.com:443']]))->toThrow(UnsupportedOptionException::class, 'allowlists')
        ->and(fn () => $this->provider->create(['env' => ['BAD-NAME' => 'x']]))->toThrow(InvalidArgumentException::class)
        ->and(File::directories($this->root))->toHaveCount(1);

    $sandbox = $this->provider->create(['env' => ['GREETING' => 'hello']]);

    expect($this->provider->get($sandbox->id())->exec('echo $GREETING')->stdout)->toBe("hello\n")
        ->and($sandbox->readdir())->toBe([]);
});

test('paths resolve against the working directory and never leave the root', function () {
    $repo = $this->sandbox->withCwd('repo');

    expect($this->sandbox->resolvePath('src/./a/../b.php'))->toBe("{$this->workspace}/src/b.php")
        ->and($repo->cwd())->toBe("{$this->workspace}/repo")
        ->and($repo->root())->toBe($this->workspace)
        ->and($repo->resolvePath('../shared.txt'))->toBe("{$this->workspace}/shared.txt")
        ->and(fn () => $repo->resolvePath('../../outside'))->toThrow(SandboxPathException::class)
        ->and(fn () => $this->sandbox->resolvePath('/etc/passwd'))->toThrow(SandboxPathException::class);
});

test('symlinks cannot reach files outside the workspace', function () {
    File::ensureDirectoryExists($outside = "{$this->root}/outside");
    File::put("{$outside}/secret.txt", 'secret');
    symlink($outside, "{$this->workspace}/link");

    expect(fn () => $this->sandbox->read('link/secret.txt'))->toThrow(SandboxPathException::class)
        ->and(fn () => $this->sandbox->write('link/planted.txt', 'x'))->toThrow(SandboxPathException::class)
        ->and(File::exists("{$outside}/planted.txt"))->toBeFalse();
});

test('writing creates missing parent directories and keeps binary contents', function () {
    $this->sandbox->write('a/b/c.bin', "\x00\xff");

    expect(File::get("{$this->workspace}/a/b/c.bin"))->toBe("\x00\xff");
});

test('commands run in the working directory without the application environment', function () {
    putenv('AI_SANDBOX_SECRET=leaked');
    $_ENV['AI_SANDBOX_SECRET'] = 'leaked';

    $this->sandbox->mkdir('repo');

    $result = $this->sandbox->withCwd('repo')->exec('pwd; echo "[$AI_SANDBOX_SECRET]"; echo "[$EXTRA]"', env: ['EXTRA' => 'given']);

    putenv('AI_SANDBOX_SECRET');
    unset($_ENV['AI_SANDBOX_SECRET']);

    expect($result->stdout)->toBe(realpath("{$this->workspace}/repo")."\n[]\n[given]\n")
        ->and($result->successful())->toBeTrue()
        ->and(fn () => $this->sandbox->exec('true', env: ['NOT-VALID' => 'x']))->toThrow(InvalidArgumentException::class);
});

test('command output streams as it is written and still comes back whole', function () {
    $chunks = [];

    $result = $this->sandbox->exec('echo one; echo two >&2; sleep 0.2; echo three', onOutput: function (string $type, string $chunk) use (&$chunks) {
        $chunks[] = [$type, $chunk];
    });

    expect($result->stdout)->toBe("one\nthree\n")
        ->and($result->stderr)->toBe("two\n")
        ->and(collect($chunks)->where(0, 'stdout')->pluck(1)->implode(''))->toBe("one\nthree\n")
        ->and(collect($chunks)->where(0, 'stderr')->pluck(1)->implode(''))->toBe("two\n");
});

test('an output callback that throws stops the command', function () {
    $started = microtime(true);

    expect(fn () => $this->sandbox->exec('echo go; sleep 5; echo late > late.txt', onOutput: fn () => throw new RuntimeException('stop')))
        ->toThrow(RuntimeException::class, 'stop');

    expect(microtime(true) - $started)->toBeLessThan(4)
        ->and(File::exists("{$this->workspace}/late.txt"))->toBeFalse();
});

test('a command that runs past its timeout is stopped and reported', function () {
    $result = $this->sandbox->exec('echo started; sleep 5', timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->stdout)->toBe("started\n")
        ->and($result->successful())->toBeFalse();
});

test('an isolated sandbox only writes inside its workspace', function () {
    isolationAvailable();

    $sandbox = (new LocalProvider(['workdir' => $this->root, 'driver' => 'local', 'isolate' => true]))->get($this->sandbox->id());

    $result = $sandbox->exec('echo inside > in.txt; echo outside > ../out.txt; echo done > /dev/null; cat in.txt');

    expect($result->stdout)->toBe("inside\n")
        ->and(File::exists("{$this->root}/out.txt"))->toBeFalse();
});

test('an isolated sandbox can be cut off from the network', function () {
    isolationAvailable();

    $offline = (new LocalProvider(['workdir' => $this->root, 'driver' => 'local', 'isolate' => true]))->create(['network' => false]);

    $result = $offline->exec('php -r \'echo @fsockopen("1.1.1.1", 80, $code, $error, 2) ? "online" : "offline";\'');

    expect($result->stdout)->toBe('offline');
});

test('the local provider isolates by default and refuses to run when the isolation tool is missing', function () {
    $path = getenv('PATH');
    putenv('PATH=/nonexistent');

    try {
        $sandbox = (new LocalProvider(['workdir' => $this->root, 'driver' => 'local']))->get($this->sandbox->id());

        expect(fn () => $sandbox->exec('echo hi > ran.txt'))->toThrow(SandboxException::class, 'Isolated local sandboxes')
            ->and(File::exists("{$this->workspace}/ran.txt"))->toBeFalse();
    } finally {
        putenv("PATH={$path}");
    }
});

test('an isolation tool that cannot start fails loudly instead of reporting a failed command', function () {
    isolationAvailable();

    Process::fake(['*' => Process::result(errorOutput: 'bwrap: setting up uid map: Permission denied', exitCode: 1)]);

    $sandbox = (new LocalProvider(['workdir' => $this->root, 'driver' => 'local']))->get($this->sandbox->id());

    expect(fn () => $sandbox->exec('echo hi'))->toThrow(SandboxException::class, 'could not start: bwrap: setting up uid map');
});

test('restoring a local checkpoint brings back the workspace without following symlinks', function () {
    $this->sandbox->write('notes.txt', 'v1');
    symlink('/etc', "{$this->workspace}/etc-link");

    $checkpoint = $this->provider->checkpoint($this->sandbox->id());

    $this->sandbox->write('notes.txt', 'v2');
    $this->sandbox->write('later.txt', 'later');

    $restored = $this->provider->restore($this->sandbox->id(), $checkpoint);

    $saved = "{$this->root}/.checkpoints/{$this->sandbox->id()}/{$checkpoint}";

    expect($restored->id())->toBe($this->sandbox->id())
        ->and($restored->read('notes.txt'))->toBe('v1')
        ->and($restored->exists('later.txt'))->toBeFalse()
        ->and(is_link("{$this->workspace}/etc-link"))->toBeTrue()
        ->and(is_link("{$saved}/etc-link"))->toBeTrue()
        ->and(fn () => $this->provider->restore($this->sandbox->id(), 'missing'))->toThrow(SandboxException::class);

    $this->provider->forgetCheckpoint($this->sandbox->id(), $checkpoint);

    expect(File::exists($saved))->toBeFalse();

    $this->provider->checkpoint($this->sandbox->id());
    $this->provider->delete($this->sandbox->id());

    expect(File::exists("{$this->root}/.checkpoints/{$this->sandbox->id()}"))->toBeFalse();
});

function isolationAvailable(): void
{
    $binary = match (PHP_OS_FAMILY) {
        'Darwin' => 'sandbox-exec',
        'Linux' => 'bwrap',
        default => null,
    };

    if ($binary === null || (new ExecutableFinder)->find($binary) === null) {
        test()->markTestSkipped('OS-level isolation is not available.');
    }
}
