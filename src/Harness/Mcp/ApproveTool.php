<?php

namespace Laravel\Ai\Harness\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ApproveTool extends Tool
{
    protected string $name = 'approve';

    protected string $description = 'Resolve a tool permission request for this harness session.';

    public function __construct(protected HarnessRun $run, protected ApprovalStore $approvals) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->required(),
            'input' => $schema->object()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['tool_name' => 'required|string', 'input' => 'present|array']);
        $name = $data['tool_name'];
        $local = str_starts_with($name, 'mcp__laravel__') ? substr($name, 14) : null;
        $name = $local !== null && isset($this->run->tools[$local]) ? $local : $name;
        $decision = $this->approvals->check($this->run, $name, $data['input']);

        if (isset($decision['updatedInput'])) {
            $decision['updatedInput'] = (object) $decision['updatedInput'];
        }

        return Response::text(json_encode($decision, JSON_THROW_ON_ERROR));
    }
}
