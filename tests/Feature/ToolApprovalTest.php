<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApproved;
use Laravel\Ai\Events\ToolRejected;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\PendingToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\PendingApprovalResponse;
use Tests\Feature\Agents\ApprovalAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Tools\ConditionalApprovalTool;
use Tests\Feature\Tools\DangerousTool;
use Tests\TestCase;

class ToolApprovalTest extends TestCase
{
    public function test_approving_a_pending_tool_call_executes_tool_and_re_prompts(): void
    {
        ApprovalAgent::fake(['The files have been deleted.']);

        $pendingToolCall = new PendingToolCall(
            tool: new DangerousTool,
            arguments: ['user_id' => 42],
            agentClass: ApprovalAgent::class,
            invocationId: 'test-invocation-id',
        );

        $response = (new ApprovalAgent)->approve($pendingToolCall);

        $this->assertEquals('The files have been deleted.', $response->text);
    }

    public function test_approving_a_pending_tool_call_dispatches_tool_approved_event(): void
    {
        Event::fake([ToolApproved::class]);

        ApprovalAgent::fake(['The files have been deleted.']);

        $pendingToolCall = new PendingToolCall(
            tool: new DangerousTool,
            arguments: ['user_id' => 42],
            agentClass: ApprovalAgent::class,
            invocationId: 'test-invocation-id',
        );

        (new ApprovalAgent)->approve($pendingToolCall);

        Event::assertDispatched(ToolApproved::class, function ($event) use ($pendingToolCall) {
            return $event->pendingToolCall === $pendingToolCall
                && $event->agent instanceof ApprovalAgent;
        });
    }

    public function test_rejecting_a_pending_tool_call_dispatches_tool_rejected_event(): void
    {
        Event::fake([ToolRejected::class]);

        ApprovalAgent::fake(['Understood, the operation was cancelled.']);

        $pendingToolCall = new PendingToolCall(
            tool: new DangerousTool,
            arguments: ['user_id' => 42],
            agentClass: ApprovalAgent::class,
            invocationId: 'test-invocation-id',
        );

        (new ApprovalAgent)->reject($pendingToolCall, 'Too risky');

        Event::assertDispatched(ToolRejected::class, function ($event) use ($pendingToolCall) {
            return $event->pendingToolCall === $pendingToolCall
                && $event->agent instanceof ApprovalAgent
                && $event->reason === 'Too risky';
        });
    }

    public function test_rejecting_without_reason_dispatches_event_with_null_reason(): void
    {
        Event::fake([ToolRejected::class]);

        ApprovalAgent::fake(['OK.']);

        $pendingToolCall = new PendingToolCall(
            tool: new DangerousTool,
            arguments: ['user_id' => 42],
            agentClass: ApprovalAgent::class,
            invocationId: 'test-invocation-id',
        );

        (new ApprovalAgent)->reject($pendingToolCall);

        Event::assertDispatched(ToolRejected::class, function ($event) {
            return is_null($event->reason);
        });
    }

    public function test_conditional_approval_tool_respects_dynamic_state(): void
    {
        $tool = new ConditionalApprovalTool(shouldRequireApproval: false);
        $this->assertFalse($tool->requiresApproval());

        $tool = new ConditionalApprovalTool(shouldRequireApproval: true);
        $this->assertTrue($tool->requiresApproval());
    }

    public function test_pending_tool_call_has_unique_id(): void
    {
        $pending1 = new PendingToolCall(new DangerousTool, [], ApprovalAgent::class, 'inv-1');
        $pending2 = new PendingToolCall(new DangerousTool, [], ApprovalAgent::class, 'inv-1');

        $this->assertNotEquals($pending1->id, $pending2->id);
    }

    public function test_pending_tool_call_serializes_to_array(): void
    {
        $pending = new PendingToolCall(
            tool: new DangerousTool,
            arguments: ['user_id' => 42],
            agentClass: ApprovalAgent::class,
            invocationId: 'inv-123',
            id: 'custom-id',
        );

        $array = $pending->toArray();

        $this->assertEquals('custom-id', $array['id']);
        $this->assertEquals('DangerousTool', $array['tool_name']);
        $this->assertEquals(['user_id' => 42], $array['arguments']);
        $this->assertEquals(ApprovalAgent::class, $array['agent_class']);
        $this->assertEquals('inv-123', $array['invocation_id']);
    }

    public function test_pending_tool_call_returns_tool_class(): void
    {
        $pending = new PendingToolCall(new DangerousTool, [], ApprovalAgent::class, 'inv-1');

        $this->assertEquals(DangerousTool::class, $pending->toolClass());
    }

    public function test_pending_approval_response_tracks_pending_calls(): void
    {
        $response = new PendingApprovalResponse('inv-1', '', new Usage, new Meta('openai', 'gpt-4'));

        $this->assertFalse($response->isPendingApproval());
        $this->assertCount(0, $response->pendingToolCalls);

        $pending = new PendingToolCall(new DangerousTool, ['user_id' => 1], ApprovalAgent::class, 'inv-1');
        $response->addPendingToolCall($pending);

        $this->assertTrue($response->isPendingApproval());
        $this->assertTrue($response->requiresApproval());
        $this->assertCount(1, $response->pendingToolCalls);
        $this->assertSame($pending, $response->pendingToolCalls->first());
    }

    public function test_pending_approval_response_extends_agent_response(): void
    {
        $response = new PendingApprovalResponse('inv-1', 'text', new Usage, new Meta('openai', 'gpt-4'));

        $this->assertInstanceOf(\Laravel\Ai\Responses\AgentResponse::class, $response);
    }
}
