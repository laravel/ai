<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Facades\Sandbox;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

class SandboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sandbox
        {provider? : The configured sandbox provider, such as local, docker, boat or boxlite}
        {--image= : The image to create the sandbox from}
        {--attach= : Attach to an existing sandbox ID instead of creating one}
        {--run= : Run this command instead of the tour}
        {--keep : Keep the sandbox instead of deleting it at the end}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Try the sandbox SDK against a configured provider';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('provider') ?? config('ai.default_sandbox');
        $provider = Sandbox::provider($name);

        intro("Sandbox provider [{$name}]");

        $sandbox = $this->option('attach')
            ? $provider->get($this->option('attach'))
            : spin(fn () => $provider->create(array_filter(['image' => $this->option('image')])), 'Creating a sandbox...');

        info("Sandbox [{$sandbox->id()}] is {$sandbox->state()->value}, working in {$sandbox->cwd()}");

        if ($this->option('run')) {
            $this->stream($sandbox, $this->option('run'));

            return $this->finish($provider, $sandbox->id());
        }

        $sandbox->write('hello.sh', "echo \"Hello from \$(uname -sm)\"\necho 'to stderr' >&2\nfor i in 1 2 3; do echo \"tick \$i\"; sleep 1; done\n");

        note('Wrote hello.sh, now running it with streamed output:');

        $this->stream($sandbox, 'sh hello.sh');

        $attached = Sandbox::provider($name)->get($sandbox->id());

        note('Attached again by ID from a fresh provider; hello.sh is still there: '.($attached->exists('hello.sh') ? 'yes' : 'no'));

        if ($provider instanceof Checkpointable) {
            $checkpoint = spin(fn () => $provider->checkpoint($sandbox->id()), 'Taking a checkpoint...');

            $attached->write('hello.sh', 'echo changed');

            $restored = spin(fn () => $provider->restore($sandbox->id(), $checkpoint), 'Restoring it...');

            note('After restoring the checkpoint, hello.sh starts with: '.strtok($restored->read('hello.sh'), "\n"));

            $provider->forgetCheckpoint($restored->id(), $checkpoint);

            $sandbox = $restored;
        }

        if ($provider instanceof Suspendable) {
            spin(fn () => $provider->suspend($sandbox->id()), 'Suspending...');

            note('Suspended: '.$sandbox->state()->value);

            spin(fn () => $provider->resume($sandbox->id()), 'Resuming...');

            note('Resumed: '.$sandbox->state()->value);
        }

        return $this->finish($provider, $sandbox->id());
    }

    /**
     * Run the command, printing its output as it arrives.
     */
    protected function stream($sandbox, string $command): void
    {
        $this->line("<fg=gray>$ {$command}</>");

        $result = $sandbox->exec($command, timeout: 60, onOutput: function (string $type, string $chunk) {
            $this->output->write($type === 'stderr' ? "<fg=red>{$chunk}</>" : $chunk);
        });

        $this->line("<fg=gray>exit {$result->exitCode}".($result->timedOut ? ', timed out' : '').'</>');
    }

    /**
     * Delete the sandbox unless asked to keep it.
     */
    protected function finish($provider, string $id): int
    {
        if ($this->option('keep')) {
            outro("Kept sandbox [{$id}]. Attach again with --attach={$id}");
        } else {
            spin(fn () => $provider->delete($id), 'Deleting the sandbox...');

            outro("Deleted sandbox [{$id}]");
        }

        return self::SUCCESS;
    }
}
