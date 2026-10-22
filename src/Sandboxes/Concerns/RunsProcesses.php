<?php

namespace Laravel\Ai\Sandboxes\Concerns;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Throwable;

trait RunsProcesses
{
    /**
     * Run the process, streaming its output and stopping it when the output callback throws.
     *
     * @param  array<int, string>  $command
     * @param  (Closure(string, string): void)|null  $onOutput
     *
     * @throws Throwable
     */
    protected function runProcess(PendingProcess $pending, array $command, ?Closure $onOutput = null): ProcessResult
    {
        if ($onOutput === null) {
            return $pending->run($command);
        }

        $failure = null;

        $process = $pending->start($command, function (string $type, string $chunk) use ($onOutput, &$failure) {
            if ($failure === null) {
                try {
                    $onOutput($type === 'err' ? 'stderr' : 'stdout', $chunk);
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            }
        });

        while ($process->running()) {
            if ($failure !== null) {
                $process->stop(0);

                throw $failure;
            }

            $process->ensureNotTimedOut();

            usleep(10_000);
        }

        $result = $process->wait();

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }
}
