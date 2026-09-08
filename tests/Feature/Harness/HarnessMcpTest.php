<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\Mcp\HarnessServer;
use Laravel\Ai\Harness\PermissionMode;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\McpServiceProvider;
use Tests\Fixtures\Harness\NoteAgent;
use Tests\Fixtures\Harness\WriteNote;

beforeEach(function () {
    $this->app->register(McpServiceProvider::class);
    $this->run = new HarnessRun(NoteAgent::class, '', '', 'sonnet', 'session', base_path(), PermissionMode::Default, ['WriteNote' => WriteNote::class], 10, 'run');
    $this->approvals = new ApprovalStore(Cache::store('array'));
    $this->transport = new class implements Transport
    {
        public array $messages = [];

        public function run(): void {}

        public function onReceive(Closure $handler): void {}

        public function send(string $message, ?string $sessionId = null): void
        {
            $this->messages[] = json_decode($message, true);
        }

        public function sessionId(): string
        {
            return 'session';
        }

        public function stream(Closure $stream): void
        {
            $stream();
        }
    };
    $this->server = new HarnessServer($this->transport, $this->run, $this->approvals);
    $this->server->start();
});

it('lists host schemas and permission callback schema over MCP', function () {
    $this->server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    $tools = collect($this->transport->messages[0]['result']['tools'])->keyBy('name');
    expect($tools['WriteNote']['inputSchema'])->toMatchArray(['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']])
        ->and($tools['approve']['inputSchema']['required'])->toBe(['tool_name', 'input']);
});

it('blocks direct approvable calls even when the CLI skips its permission callback then executes edited input', function () {
    Event::fake([InvokingTool::class, ToolInvoked::class]);
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'WriteNote', 'arguments' => ['text' => 'original']]];
    $this->server->handle(json_encode($request));
    expect($this->transport->messages[0]['result']['isError'])->toBeTrue()->and(Cache::get('harness-test-note'))->toBeNull();
    Event::assertNotDispatched(InvokingTool::class);
    $pending = $this->approvals->pending($this->run)->first();
    $this->approvals->resolve($this->run, Decisions::from([$pending->id => Decision::edit(['text' => 'edited'])]));
    $this->server->handle(json_encode($request));
    expect($this->transport->messages[1]['result']['content'][0]['text'])->toBe('Wrote: edited')->and(Cache::get('harness-test-note'))->toBe('edited');
    Event::assertDispatched(ToolInvoked::class, fn ($event) => $event->arguments === ['text' => 'edited'] && $event->result === 'Wrote: edited');
});

it('answers native permission requests with the documented JSON text payload', function () {
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'approve', 'arguments' => ['tool_name' => 'Bash', 'input' => ['command' => 'ls']]]];
    $this->server->handle(json_encode($request));
    $payload = json_decode($this->transport->messages[0]['result']['content'][0]['text'], true);
    expect($payload['behavior'])->toBe('deny');
    $this->approvals->resolve($this->run, Decision::approveAll());
    $this->server->handle(json_encode($request));
    expect(json_decode($this->transport->messages[1]['result']['content'][0]['text'], true))->toBe(['behavior' => 'allow', 'updatedInput' => ['command' => 'ls']]);
});

it('round trips a denial and an edited approval through the CLI and Artisan subprocesses', function () {
    Event::fake([ToolApprovalRequested::class, ToolApprovalResolved::class]);
    config([
        'ai.harnesses.claude-code.binary' => __DIR__.'/../../Fixtures/claude/replay',
        'ai.harnesses.claude-code.cache_store' => 'file',
        'ai.harnesses.claude-code.env' => ['HARNESS_TEST_MODE' => 'mcp', 'CACHE_STORE' => 'file', 'TESTBENCH_WORKING_PATH' => realpath(__DIR__.'/../../..')],
    ]);
    $agent = new NoteAgent;
    $response = $agent->prompt('write a note');
    expect($response->pendingApprovals)->toHaveCount(1)->and($response->finishReason)->toBe('tool_approval');
    Event::assertDispatched(ToolApprovalRequested::class);

    $resumed = $agent->prompt(Decisions::from([
        $response->pendingApprovals->first()->id => Decision::edit(['text' => 'approved edit']),
    ]), $response->sessionId);
    expect($resumed->text)->toBe('Wrote: approved edit')->and($resumed->pendingApprovals)->toBeEmpty();
    Event::assertDispatched(ToolApprovalResolved::class);
    Cache::store('file')->forget('harness-test-note');
});

it('streams a pending approval against the native call id and includes the resumable session', function () {
    config([
        'ai.harnesses.claude-code.binary' => __DIR__.'/../../Fixtures/claude/replay',
        'ai.harnesses.claude-code.cache_store' => 'file',
        'ai.harnesses.claude-code.env' => ['HARNESS_TEST_MODE' => 'mcp', 'CACHE_STORE' => 'file', 'TESTBENCH_WORKING_PATH' => realpath(__DIR__.'/../../..')],
    ]);
    $stream = (new NoteAgent)->stream('write a note');
    $response = $stream->usingVercelDataProtocol()->toResponse(request());
    $content = '';
    ob_start(function ($buffer) use (&$content) {
        $content .= $buffer;

        return '';
    });
    $response->sendContent();
    ob_end_clean();
    $pending = $stream->events->whereInstanceOf(ToolApprovalRequest::class)->first()->pendingApprovals->first();
    expect($content)->toContain('"toolCallId":"call-1","approvalId":"'.$pending->id.'"')
        ->not->toContain('tool-output-error')
        ->and($pending->toolCallId)->toBe('call-1');
});
