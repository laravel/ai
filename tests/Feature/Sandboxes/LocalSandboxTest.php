<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Ai;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Laravel\Ai\Sandboxes\LocalFactory;
use Laravel\Ai\Sandboxes\Sandbox;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Sandbox\Bash;
use Laravel\Ai\Tools\Sandbox\Edit;
use Laravel\Ai\Tools\Sandbox\Glob;
use Laravel\Ai\Tools\Sandbox\Grep;
use Laravel\Ai\Tools\Sandbox\Read;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-sandboxes-'.uniqid();
    $this->sandbox = (new LocalFactory(['root' => $this->root, 'timeout' => 5, 'isolate' => false]))->create('conversation-1');
});

afterEach(fn () => File::deleteDirectory($this->root));

test('paths resolve inside the workspace and never outside it', function () {
    $cwd = "{$this->root}/conversation-1";

    expect($this->sandbox->resolvePath('src/./a/../b.php'))->toBe("{$cwd}/src/b.php")
        ->and($this->sandbox->resolvePath("{$cwd}//src/b.php"))->toBe("{$cwd}/src/b.php")
        ->and(fn () => $this->sandbox->resolvePath('../conversation-2/secret'))->toThrow(SandboxPathException::class)
        ->and(fn () => $this->sandbox->resolvePath('/etc/passwd'))->toThrow(SandboxPathException::class)
        ->and(fn () => $this->sandbox->withCwd('repo')->resolvePath('../outside'))->toThrow(SandboxPathException::class);
});

test('symlinks cannot reach files outside the workspace', function () {
    File::ensureDirectoryExists($outside = "{$this->root}/outside");
    File::put("{$outside}/secret.txt", 'secret');
    symlink($outside, "{$this->root}/conversation-1/link");

    expect((new Read($this->sandbox))->handle(new Request(['path' => 'link/secret.txt'])))
        ->toStartWith('Path [')
        ->and(fn () => $this->sandbox->write('link/planted.txt', 'x'))->toThrow(SandboxPathException::class)
        ->and(File::exists("{$outside}/planted.txt"))->toBeFalse();
});

test('writing creates missing parent directories', function () {
    $this->sandbox->write('a/b/c.txt', 'deep');

    expect(File::get("{$this->root}/conversation-1/a/b/c.txt"))->toBe('deep');
});

test('commands run in the workspace without the application environment', function () {
    putenv('AI_SANDBOX_SECRET=leaked');
    $_ENV['AI_SANDBOX_SECRET'] = 'leaked';

    $result = $this->sandbox->exec('pwd; echo "[$AI_SANDBOX_SECRET]"; echo "[$EXTRA]"', env: ['EXTRA' => 'given']);

    putenv('AI_SANDBOX_SECRET');
    unset($_ENV['AI_SANDBOX_SECRET']);

    expect($result->stdout)->toBe(realpath("{$this->root}/conversation-1")."\n[]\n[given]\n")
        ->and($result->successful())->toBeTrue();
});

test('a command that runs past its timeout is stopped and reported', function () {
    $result = $this->sandbox->exec('echo started; sleep 5', timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->stdout)->toBe("started\n")
        ->and($result->successful())->toBeFalse();
});

test('an isolated sandbox only writes inside its workspace', function () {
    isolationAvailable();

    $sandbox = (new LocalFactory(['root' => $this->root, 'isolate' => true]))->create('conversation-1');

    $result = $sandbox->exec('echo inside > in.txt; echo outside > ../out.txt; echo done > /dev/null; cat in.txt');

    expect($result->stdout)->toBe("inside\n")
        ->and(File::exists("{$this->root}/out.txt"))->toBeFalse();
});

test('an isolated sandbox can be cut off from the network', function () {
    isolationAvailable();

    $offline = (new LocalFactory(['root' => $this->root, 'isolate' => true, 'network' => false]))->create('conversation-1');

    $result = $offline->exec('php -r \'echo @fsockopen("1.1.1.1", 80, $code, $error, 2) ? "online" : "offline";\'');

    expect($result->stdout)->toBe('offline');
});

test('the local driver isolates by default and refuses to run when the isolation tool is missing', function () {
    $path = getenv('PATH');
    putenv('PATH=/nonexistent');

    try {
        $sandbox = (new LocalFactory(['root' => $this->root]))->create('conversation-1');

        expect(fn () => $sandbox->exec('echo hi > ran.txt'))->toThrow(RuntimeException::class, 'Isolated local sandboxes')
            ->and(File::exists("{$this->root}/conversation-1/ran.txt"))->toBeFalse();
    } finally {
        putenv("PATH={$path}");
    }
});

test('an isolation tool that cannot start fails loudly instead of reporting a failed command', function () {
    if ((new ExecutableFinder)->find(PHP_OS_FAMILY === 'Darwin' ? 'sandbox-exec' : 'bwrap') === null) {
        $this->markTestSkipped('OS-level isolation is not available.');
    }

    Process::fake(['*' => Process::result(errorOutput: 'bwrap: setting up uid map: Permission denied', exitCode: 1)]);

    $sandbox = (new LocalFactory(['root' => $this->root]))->create('conversation-1');

    expect(fn () => $sandbox->exec('echo hi'))->toThrow(RuntimeException::class, 'could not start: bwrap: setting up uid map');
});

test('restoring a local checkpoint brings back the workspace without following symlinks', function () {
    $factory = new LocalFactory(['root' => $this->root, 'isolate' => false]);

    $this->sandbox->write('notes.txt', 'v1');
    symlink('/etc', "{$this->root}/conversation-1/etc-link");

    $checkpoint = $factory->checkpoint('conversation-1');

    $this->sandbox->write('notes.txt', 'v2');
    $this->sandbox->write('later.txt', 'later');

    $factory->restore('conversation-1', $checkpoint);

    expect($this->sandbox->read('notes.txt'))->toBe('v1')
        ->and($this->sandbox->exists('later.txt'))->toBeFalse()
        ->and(is_link("{$this->root}/conversation-1/etc-link"))->toBeTrue()
        ->and(File::exists("{$this->root}/.checkpoints/conversation-1/{$checkpoint}/etc-link/hosts"))->toBeTrue()
        ->and(is_link("{$this->root}/.checkpoints/conversation-1/{$checkpoint}/etc-link"))->toBeTrue()
        ->and(fn () => $factory->restore('conversation-1', 'missing'))->toThrow(RuntimeException::class);

    $factory->forgetCheckpoint('conversation-1', $checkpoint);

    expect(File::exists("{$this->root}/.checkpoints/conversation-1/{$checkpoint}"))->toBeFalse()
        ->and($this->sandbox->read('notes.txt'))->toBe('v1');

    $factory->checkpoint('conversation-1');
    $factory->forget('conversation-1');

    expect(File::exists("{$this->root}/.checkpoints/conversation-1"))->toBeFalse();
});

test('a factory forgets a sandbox by removing its workspace', function () {
    $this->sandbox->write('a.txt', 'a');

    (new LocalFactory(['root' => $this->root]))->forget('conversation-1');

    expect(File::exists("{$this->root}/conversation-1"))->toBeFalse();
});

test('read returns a line window and says how to continue', function () {
    $this->sandbox->write('lines.txt', "one\ntwo\nthree\nfour");

    expect((new Read($this->sandbox))->handle(new Request(['path' => 'lines.txt', 'offset' => 2, 'limit' => 2])))
        ->toBe("two\nthree\n[lines 2-3 of 4; pass offset to read more]")
        ->and((new Read($this->sandbox))->handle(new Request(['path' => 'missing.txt'])))
        ->toBe('File [missing.txt] does not exist.')
        ->and((new Read($this->sandbox))->handle(new Request(['path' => '../escape.txt'])))
        ->toStartWith('Path [../escape.txt] is outside the sandbox directory');
});

test('read returns the start of a line longer than the output limit', function () {
    $this->sandbox->write('min.js', str_repeat('x', 60 * 1024)."\nnext");

    $output = (new Read($this->sandbox))->handle(new Request(['path' => 'min.js']));

    expect($output)->toStartWith(str_repeat('x', 100))
        ->toContain('[line truncated]')
        ->toContain('[lines 1-1 of 2;');
});

test('bash keeps output that is only a zero', function () {
    expect((new Bash($this->sandbox))->handle(new Request(['command' => 'echo 0'])))->toBe("0\n[exit code 0]");
});

test('glob reports a directory that does not exist', function () {
    expect((new Glob($this->sandbox))->handle(new Request(['pattern' => '*', 'path' => 'missing'])))->toBe('Directory [missing] does not exist.');
});

test('read refuses binary files', function () {
    $this->sandbox->write('image.png', "\x89PNG\0\0");

    expect((new Read($this->sandbox))->handle(new Request(['path' => 'image.png'])))
        ->toBe('File [image.png] is binary and cannot be read as text.');
});

test('edit replaces one exact match and refuses missing or ambiguous text', function () {
    $this->sandbox->write('app.php', "a = 1\nb = 1\n");

    $edit = new Edit($this->sandbox);

    expect($edit->handle(new Request(['path' => 'app.php', 'old' => '= 1', 'new' => '= 2'])))
        ->toBe('The text to replace appears 2 times in [app.php]. Include more surrounding text or set replace_all.')
        ->and($edit->handle(new Request(['path' => 'app.php', 'old' => 'c = 1', 'new' => 'c = 2'])))
        ->toBe('The text to replace was not found in [app.php].')
        ->and($edit->handle(new Request(['path' => 'nope.php', 'old' => 'a', 'new' => 'b'])))
        ->toBe('File [nope.php] does not exist.')
        ->and($edit->handle(new Request(['path' => 'app.php', 'old' => 'a = 1', 'new' => 'a = 2'])))
        ->toBe('Replaced 1 occurrence(s) in [app.php].')
        ->and($edit->handle(new Request(['path' => 'app.php', 'old' => '= ', 'new' => ':= ', 'replace_all' => true])))
        ->toBe('Replaced 2 occurrence(s) in [app.php].')
        ->and($this->sandbox->read('app.php'))->toBe("a := 2\nb := 1\n");
});

test('glob matches workspace-relative paths and skips dependency directories', function () {
    $this->sandbox->write('src/App.php', '');
    $this->sandbox->write('src/Http/Kernel.php', '');
    $this->sandbox->write('src/readme.md', '');
    $this->sandbox->write('vendor/lib/Lib.php', '');

    $glob = new Glob($this->sandbox);

    expect($glob->handle(new Request(['pattern' => '**/*.php'])))->toBe("src/App.php\nsrc/Http/Kernel.php")
        ->and($glob->handle(new Request(['pattern' => '*.php', 'path' => 'src'])))->toBe('src/App.php')
        ->and($glob->handle(new Request(['pattern' => '*.rb'])))->toBe('No files found.');
});

test('grep returns matching lines relative to the workspace', function () {
    $this->sandbox->write('src/App.php', "<?php\n\nclass App {}\n");
    $this->sandbox->write('src/notes.md', "class notes\n");

    $grep = new Grep($this->sandbox);

    expect($grep->handle(new Request(['pattern' => 'class App', 'glob' => '*.php'])))->toBe('src/App.php:3:class App {}')
        ->and($grep->handle(new Request(['pattern' => 'CLASS NOTES', 'ignore_case' => true])))->toBe('src/notes.md:1:class notes')
        ->and($grep->handle(new Request(['pattern' => 'missing'])))->toBe('No matches found.');
});

test('custom drivers register through the manager', function () {
    $factory = new LocalFactory(['root' => $this->root]);

    config(['ai.sandboxes.custom' => ['driver' => 'custom']]);

    Ai::extendSandbox('custom', fn ($app, array $config) => $factory);

    expect(Ai::sandbox('custom'))->toBe($factory)
        ->and(Ai::sandbox())->toBeInstanceOf(LocalFactory::class);
});

test('a deferred sandbox is not created until it is used', function () {
    $created = 0;

    $sandbox = Sandbox::defer(function () use (&$created) {
        $created++;

        return $this->sandbox;
    })->withCwd('repo');

    expect($created)->toBe(0);

    $sandbox->write('a.txt', 'a');
    $sandbox->read('a.txt');

    expect($created)->toBe(1)
        ->and(File::get("{$this->root}/conversation-1/repo/a.txt"))->toBe('a');
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
