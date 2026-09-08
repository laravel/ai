<?php

namespace Laravel\Ai\Harness\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessAgent;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;

use function Laravel\Ai\ulid;

class HostTool extends McpTool
{
    public function __construct(
        string $name,
        protected Tool $tool,
        protected HarnessAgent $agent,
        protected HarnessRun $run,
        protected ApprovalStore $approvals,
    ) {
        $this->name = $name;
        $this->description = (string) $tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(McpRequest $request): Response
    {
        $arguments = $request->all();
        $id = ulid();
        $toolRequest = new Request($arguments, toolInvocationId: $id);

        if ($this->tool instanceof Approvable && ($approval = $this->tool->shouldRequestApproval($toolRequest)) !== null) {
            $decision = $this->approvals->check($this->run, $this->name, $arguments, $approval->reason);

            if ($decision['behavior'] !== 'allow') {
                return Response::error(json_encode($decision, JSON_THROW_ON_ERROR));
            }

            $arguments = $decision['updatedInput'];
            $toolRequest = new Request($arguments, toolInvocationId: $id);
        }

        event(new InvokingTool($this->run->id, $id, $this->agent, $this->tool, $arguments));
        $start = microtime(true);
        $result = $this->tool->handle($toolRequest);
        event(new ToolInvoked($this->run->id, $id, $this->agent, $this->tool, $arguments, $result, (microtime(true) - $start) * 1000));

        return Response::text((string) $result);
    }
}
