<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Sandboxes\DockerFactory;
use Laravel\Ai\Sandboxes\Exceptions\SandboxDied;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Sandbox\Glob;
use Laravel\Ai\Tools\Sandbox\Grep;
use Tests\Fixtures\Agents\SandboxedAgent;

beforeEach(function () {
    if (! Process::run(['docker', 'info'])->successful()) {
        $this->markTestSkipped('Docker is not available.');
    }

    $this->config = [
        'driver' => 'docker',
        'image' => env('AI_SANDBOX_TEST_IMAGE', 'alpine:3.20'),
        'workdir' => '/workspace',
        'memory' => '256m',
        'cpus' => '1',
        'network' => 'none',
        'timeout' => 30,
    ];

    $this->factory = new DockerFactory($this->config);
    $this->id = 'test-'.uniqid();
});

afterEach(function () {
    if (isset($this->factory)) {
        $this->factory->forget($this->id);
    }
});

test('commands run inside the container, isolated from the host and the network', function () {
    $sandbox = $this->factory->create($this->id);

    $result = $sandbox->exec('pwd; ls /sys/class/net; test -e /Users && echo host-visible; true');

    expect($result->stdout)->toBe("/workspace\nlo\n")
        ->and($result->successful())->toBeTrue();
});

test('files written in the sandbox round trip through the container', function () {
    $sandbox = $this->factory->create($this->id);

    $sandbox->write('src/a b/it\'s.txt', "line one\n\$HOME `x`\n");

    expect($sandbox->read('src/a b/it\'s.txt'))->toBe("line one\n\$HOME `x`\n")
        ->and($sandbox->stat('src/a b/it\'s.txt'))->isFile->toBeTrue()
        ->and($sandbox->stat('src'))->isDirectory->toBeTrue()
        ->and($sandbox->stat('missing'))->toBeNull()
        ->and($sandbox->readdir('src'))->toBe(['a b']);

    $sandbox->rm('src', recursive: true);

    expect($sandbox->exists('src'))->toBeFalse();
});

test('the workspace survives the container stopping and is reattached by name', function () {
    $this->factory->create($this->id)->write('kept.txt', 'still here');

    Process::run(['docker', 'stop', '-t', '0', $this->factory->name($this->id)]);

    expect($this->factory->create($this->id)->read('kept.txt'))->toBe('still here');
});

test('a command past its timeout is stopped inside the container', function () {
    $sandbox = $this->factory->create($this->id);

    $result = $sandbox->exec('echo started; sleep 30', timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->stdout)->toBe("started\n")
        ->and($sandbox->exec('ps -o args | grep -c "[s]leep 30"')->stdout)->toBe("0\n");
});

test('a removed container surfaces as a dead sandbox', function () {
    $sandbox = $this->factory->create($this->id);

    Process::run(['docker', 'rm', '-f', $this->factory->name($this->id)]);

    expect(fn () => $sandbox->exec('true'))->toThrow(SandboxDied::class);
});

test('forgetting a sandbox removes its container and volume', function () {
    $this->factory->create($this->id);

    $this->factory->forget($this->id);

    expect(Process::run(['docker', 'inspect', $this->factory->name($this->id)])->successful())->toBeFalse()
        ->and(Process::run(['docker', 'volume', 'inspect', $this->factory->name($this->id)])->successful())->toBeFalse();
});

test('restoring a checkpoint brings back both the workspace and the container filesystem', function () {
    $sandbox = $this->factory->create($this->id);

    $sandbox->write('notes.txt', 'v1');
    $sandbox->exec('touch /etc/installed-by-agent');

    $checkpoint = $this->factory->checkpoint($this->id);

    $sandbox->write('notes.txt', 'v2');
    $sandbox->write('later.txt', 'later');
    $sandbox->exec('rm /etc/installed-by-agent');

    $this->factory->restore($this->id, $checkpoint);

    $restored = $this->factory->create($this->id);

    expect($restored->read('notes.txt'))->toBe('v1')
        ->and($restored->exists('later.txt'))->toBeFalse()
        ->and($restored->exec('test -f /etc/installed-by-agent')->successful())->toBeTrue();
});

test('forgetting a sandbox removes its checkpoints', function () {
    $this->factory->create($this->id)->write('a.txt', 'a');

    $checkpoint = $this->factory->checkpoint($this->id);

    $this->factory->forget($this->id);

    $image = strtolower($this->factory->name($this->id)).':'.$checkpoint;

    expect(Process::run(['docker', 'image', 'inspect', $image])->successful())->toBeFalse()
        ->and(Process::run(['docker', 'volume', 'inspect', $this->factory->name($this->id).'-'.$checkpoint])->successful())->toBeFalse();
});

test('a forgotten checkpoint removes its image and volume', function () {
    $this->factory->create($this->id)->write('a.txt', 'a');

    $checkpoint = $this->factory->checkpoint($this->id);

    $this->factory->forgetCheckpoint($this->id, $checkpoint);

    expect(Process::run(['docker', 'image', 'inspect', strtolower($this->factory->name($this->id)).':'.$checkpoint])->successful())->toBeFalse()
        ->and(Process::run(['docker', 'volume', 'inspect', $this->factory->name($this->id).'-'.$checkpoint])->successful())->toBeFalse();
});

test('a checkpoint without its workspace copy is refused before the workspace is touched', function () {
    $this->factory->create($this->id)->write('notes.txt', 'v1');

    $checkpoint = $this->factory->checkpoint($this->id);

    Process::run(['docker', 'volume', 'rm', '-f', $this->factory->name($this->id).'-'.$checkpoint]);

    expect(fn () => $this->factory->restore($this->id, $checkpoint))->toThrow(RuntimeException::class)
        ->and($this->factory->create($this->id)->read('notes.txt'))->toBe('v1');
});

test('checkpointing a suspended sandbox leaves it stopped', function () {
    $this->factory->create($this->id)->write('a.txt', 'a');
    $this->factory->suspend($this->id);

    $this->factory->checkpoint($this->id);

    expect(trim(Process::run(['docker', 'inspect', '-f', '{{.State.Running}}', $this->factory->name($this->id)])->output()))->toBe('false');
});

test('a suspended sandbox stops its container and resumes with its files on the next create', function () {
    $this->factory->create($this->id)->write('kept.txt', 'kept');

    $this->factory->suspend($this->id);

    expect(trim(Process::run(['docker', 'inspect', '-f', '{{.State.Running}}', $this->factory->name($this->id)])->output()))->toBe('false')
        ->and($this->factory->create($this->id)->read('kept.txt'))->toBe('kept');
});

test('glob and grep work against the container', function () {
    $sandbox = $this->factory->create($this->id);

    $sandbox->write('src/App.php', "<?php\nclass App {}\n");
    $sandbox->write('node_modules/x/index.php', 'class App {}');

    expect((new Glob($sandbox))->handle(new Request(['pattern' => '**/*.php'])))->toBe('src/App.php')
        ->and((new Grep($sandbox))->handle(new Request(['pattern' => 'class App', 'path' => 'src'])))->toBe('src/App.php:2:class App {}');
});

test('a remembered agent keeps its container workspace across turns', function () {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.default_sandbox', 'docker');
    Config::set('ai.sandboxes.docker', $this->config);

    $user = (object) ['id' => 1];

    SandboxedAgent::fake([
        new ToolCall('call_1', 'Bash', ['command' => 'echo built > out.txt']),
        'Built.',
        new ToolCall('call_2', 'Read', ['path' => 'out.txt']),
        'It says built.',
    ]);

    $first = (new SandboxedAgent)->forUser($user)->prompt('Build');

    $this->id = $first->conversationId;

    $second = (new SandboxedAgent)->continue($first->conversationId, $user)->prompt('Read the output');

    expect($second->toolResults[0]->result)->toBe("built\n")
        ->and(Ai::sandbox('docker'))->toBeInstanceOf(DockerFactory::class)
        ->and(trim(Process::run(['docker', 'inspect', '-f', '{{.State.Running}}', $this->factory->name($this->id)])->output()))->toBe('false');
});
