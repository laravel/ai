<?php

namespace Laravel\Ai\Harness\Mcp;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessAgent;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\PermissionMode;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use LogicException;

class HarnessServer extends Server
{
    public function __construct(Transport $transport, HarnessRun $run, ApprovalStore $approvals)
    {
        parent::__construct($transport);

        if ($run->tools !== []) {
            $agent = app($run->agent);

            if (! $agent instanceof HarnessAgent) {
                throw new LogicException('The stored harness agent is invalid.');
            }

            foreach ($agent->tools() as $tool) {
                if (! $tool instanceof Tool) {
                    throw new LogicException('The harness agent returned an invalid tool.');
                }

                $name = method_exists($tool, 'name') ? $tool->name() : class_basename($tool);

                if (($run->tools[$name] ?? null) !== $tool::class) {
                    throw new LogicException('The harness tool definitions changed while the run was starting.');
                }

                $this->tools[] = new HostTool($name, $tool, $agent, $run, $approvals);
            }

            if (count($this->tools) !== count($run->tools)) {
                throw new LogicException('The harness tool definitions changed while the run was starting.');
            }
        }

        if ($run->permissionMode !== PermissionMode::BypassPermissions) {
            $this->tools[] = new ApproveTool($run, $approvals);
        }
    }
}
