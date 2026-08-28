<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Attributes\Bind;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Tools\Request;

use function Laravel\Ai\agent;

uses(RefreshDatabase::class)->beforeEach(function (): void {
    Schema::create('tickets', function (Blueprint $table): void {
        $table->id();
        $table->string('subject');
        $table->string('slug')->nullable();
    });

    Schema::create('ticket_statuses', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
})->in(__FILE__);

function bindingGateway(): object
{
    return new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = []): string
        {
            return $this->executeTool($tool, $arguments);
        }
    };
}

test('models are resolved into the tool handler', function (): void {
    $ticket = BindableTicket::create(['subject' => 'Login broken']);
    $status = BindableTicketStatus::create(['name' => 'Closed']);

    $result = bindingGateway()->invoke(new ChangeTicketStatusTool, [
        'ticket_id' => $ticket->id,
        'status' => $status->id,
    ]);

    expect($result)->toBe('Login broken is now Closed.');
});

test('an argument name is matched against the snake case parameter name', function (): void {
    $ticket = BindableTicket::create(['subject' => 'Snaked']);

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Read a ticket.';
        }

        public function handle(Request $request, ?BindableTicket $ticketRecord = null): string
        {
            return $ticketRecord->subject;
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return ['ticket_record' => $schema->integer()->required()];
        }
    };

    expect(bindingGateway()->invoke($tool, ['ticket_record' => $ticket->id]))->toBe('Snaked');
});

test('an unresolved model is returned to the model as the tool result', function (): void {
    $status = BindableTicketStatus::create(['name' => 'Closed']);

    $result = bindingGateway()->invoke(new ChangeTicketStatusTool, [
        'ticket_id' => 42,
        'status' => $status->id,
    ]);

    expect($result)->toBe('No bindable ticket was found matching id [42].');
});

test('a custom route key name is used to resolve and to report a miss', function (): void {
    $ticket = BindableSluggedTicket::create(['subject' => 'Slugged', 'slug' => 'slugged']);

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Read a ticket by slug.';
        }

        public function handle(Request $request, #[Bind('ticket')] ?BindableSluggedTicket $record = null): string
        {
            return $record->subject;
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return ['ticket' => $schema->string()->required()];
        }
    };

    expect(bindingGateway()->invoke($tool, ['ticket' => 'slugged']))->toBe('Slugged')
        ->and(bindingGateway()->invoke($tool, ['ticket' => 'missing']))
        ->toBe('No bindable slugged ticket was found matching slug [missing].');
});

test('an argument the handler does not bind is left alone', function (): void {
    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Echo an argument.';
        }

        public function handle(Request $request): string
        {
            return (string) $request->integer('ticket_id');
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return ['ticket_id' => $schema->integer()->required()];
        }
    };

    expect(bindingGateway()->invoke($tool, ['ticket_id' => 42]))->toBe('42');
});

test('the raw arguments remain available on the request', function (): void {
    $ticket = BindableTicket::create(['subject' => 'Raw']);

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Report the raw argument.';
        }

        public function handle(Request $request, ?BindableTicket $ticket = null): string
        {
            return $ticket->subject.':'.$request->integer('ticket').':'.$request->model('ticket')->id;
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return ['ticket' => $schema->integer()->required()];
        }
    };

    expect(bindingGateway()->invoke($tool, ['ticket' => $ticket->id]))
        ->toBe("Raw:{$ticket->id}:{$ticket->id}");
});

test('an approvable tool sees the resolved model when requesting approval', function (): void {
    Event::fake([ToolApprovalRequested::class]);

    $ticket = BindableTicket::create(['subject' => 'Login broken']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'CloseTicketTool',
                'input' => ['ticket' => $ticket->id],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    agent('Close tickets.', tools: [new CloseTicketTool])->prompt('Close it', provider: 'anthropic');

    Event::assertDispatched(ToolApprovalRequested::class, fn (ToolApprovalRequested $event): bool => $event->pendingApprovals[0]->reason === 'Close ticket: Login broken?');
});

class BindableTicket extends Model
{
    protected $table = 'tickets';

    public $timestamps = false;

    protected $guarded = [];
}

class BindableSluggedTicket extends Model
{
    protected $table = 'tickets';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class BindableTicketStatus extends Model
{
    protected $table = 'ticket_statuses';

    public $timestamps = false;

    protected $guarded = [];
}

class ChangeTicketStatusTool implements Tool
{
    public function description(): string
    {
        return 'Change the status of a ticket.';
    }

    public function handle(
        Request $request,
        #[Bind('ticket_id')] ?BindableTicket $ticket = null,
        #[Bind] ?BindableTicketStatus $status = null,
    ): string {
        return "{$ticket->subject} is now {$status->name}.";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_id' => $schema->integer()->required(),
            'status' => $schema->integer()->required(),
        ];
    }
}

class CloseTicketTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Close a ticket.';
    }

    public function handle(Request $request, ?BindableTicket $ticket = null): string
    {
        return "Closed {$ticket->subject}.";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['ticket' => $schema->integer()->required()];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required("Close ticket: {$request->model('ticket')->subject}?");
    }
}

test('an approvable tool is not gated when its models cannot be resolved', function (): void {
    Event::fake([ToolApprovalRequested::class]);

    Http::fakeSequence()
        ->push([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'CloseTicketTool',
                'input' => ['ticket' => 999],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->push([
            'id' => 'msg_text_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'That ticket does not exist.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

    $response = agent('Close tickets.', tools: [new CloseTicketTool])->prompt('Close it', provider: 'anthropic');

    expect($response->text)->toBe('That ticket does not exist.');

    Event::assertNotDispatched(ToolApprovalRequested::class);
});
