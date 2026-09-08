<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\Mcp\HarnessServer;
use Laravel\Mcp\Server\Transport\StdioTransport;
use LogicException;

class HarnessMcpCommand extends Command
{
    protected $signature = 'ai:harness-mcp {run}';

    protected $description = 'Serve tools for an active harness run over stdio';

    protected $hidden = true;

    public function handle(): int
    {
        if (! class_exists(StdioTransport::class)) {
            throw new LogicException('Install laravel/mcp to serve harness tools.');
        }

        $cache = Cache::store(getenv('AI_HARNESS_RUN_CACHE_STORE') ?: null);
        $run = $cache->get('ai:harness:run:'.$this->argument('run'));

        if (! $run instanceof HarnessRun) {
            throw new LogicException('The harness run does not exist or has expired.');
        }

        $transport = new StdioTransport($run->id);
        $server = new HarnessServer($transport, $run, new ApprovalStore($cache, config('ai.harnesses.'.$run->harness.'.approval_ttl', 3600)));
        $server->start();
        $transport->run();

        return self::SUCCESS;
    }
}
