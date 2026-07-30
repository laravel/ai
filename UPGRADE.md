# Upgrade Guide

## Upgrading To 1.0 From 0.x

### Provider Aware Agent Tools

**Likelihood Of Impact: High**

The `HasTools` contract now receives the provider that is being invoked, allowing an agent to offer a different set of tools to each provider. Agents implementing `HasTools` should add the `$provider` argument to their `tools` method:

```php
use Laravel\Ai\Enums\Lab;

/**
 * Get the tools available to the agent for the given provider.
 */
public function tools(Lab|string $provider): iterable
{
    return [new SearchKnowledgeBase];
}
```

The argument is the `Lab` the provider belongs to, so tools may be limited to the providers that support them. This is particularly useful when failing over between providers, since the tools are resolved again for each provider that is attempted:

```php
public function tools(Lab|string $provider): iterable
{
    return match ($provider) {
        Lab::Anthropic => [new SearchKnowledgeBase, new WebFetch],
        default => [new SearchKnowledgeBase],
    };
}
```

Connections using the `openai-compatible` driver receive their connection name instead, matching the existing behavior of `providerOptions`.

## Upgrading To 0.11 From 0.10

### Connection Failures Throw `ProviderConnectionException`

**Likelihood Of Impact: Medium**

A failed connection to a provider is now caught and rethrown as `Laravel\Ai\Exceptions\ProviderConnectionException`, which implements `FailoverableException` so the request fails over to your next configured provider or model.

Code that catches the underlying HTTP client exception must be updated:

```php
// Before...
use Illuminate\Http\Client\ConnectionException;

try {
    $agent->prompt('...');
} catch (ConnectionException $e) {
    // ...
}

// After...
use Laravel\Ai\Exceptions\ProviderConnectionException;

try {
    $agent->prompt('...');
} catch (ProviderConnectionException $e) {
    $original = $e->getPrevious();
}
```

In addition, the status codes that trigger failover now include `502`, `504`, `520`, `522`, and `524` rather than only `503`, and Anthropic requests rejected for hitting a usage limit now fail over as well.

### Stream Errors Throw Instead Of Ending The Run

**Likelihood Of Impact: Medium**

When a provider reports an error inside the stream body rather than throwing, the step previously ended quietly, handing the consumer a partial `text`, no finish reason, and no terminal `StreamEnd` event. The step now throws `Laravel\Ai\Exceptions\StreamErrorException`, which carries the provider's own `Error` event:

```php
use Laravel\Ai\Exceptions\StreamErrorException;

try {
    foreach ($agent->stream('...') as $event) {
        // ...
    }
} catch (StreamErrorException $e) {
    $e->error; // The Laravel\Ai\Streaming\Events\Error event, if the provider sent one...
}
```

A run that reaches the end of its stream now always emits a `StreamEnd` event.

### Queued Fakes Dispatch The Real Job

**Likelihood Of Impact: Low**

Queueing an agent prompt, transcription, image, audio, or embeddings generation while faking now dispatches the real job, so `then(...)` callbacks execute. The `Laravel\Ai\FakePendingDispatch` class has been removed.

Existing tests continue to pass unless they relied on the callback never running.

### Gemini Default Text Model

**Likelihood Of Impact: Medium**

The Gemini provider's default and smartest text models are now `gemini-3.7-flash` instead of `gemini-3.6-flash`. To stay on the previous model, pin it in your provider configuration:

```php
'gemini' => [
    'driver' => 'gemini',
    'key' => env('GEMINI_API_KEY'),
    'models' => [
        'text' => [
            'default' => 'gemini-3.6-flash',
            'smartest' => 'gemini-3.6-flash',
        ],
    ],
],
```

### Event Constructor Signatures

**Likelihood Of Impact: Low**

Agent runs now thread an invocation ID through the events they dispatch, and tool events report how long the tool ran:

- `Laravel\Ai\Events\AgentFailedOver` takes a new required `string $invocationId` as its first constructor argument.
- `Laravel\Ai\Events\ToolInvoked` takes a new required `float $time` as its final constructor argument, the wall time spent in the tool's handler in milliseconds.

No changes are needed if you only listen for these events. If you construct them directly, such as when dispatching them by hand in a test, update the arguments to match.

`Laravel\Ai\Tools\Request` also accepts a third `?string $toolInvocationId` argument, exposed through `toolInvocationId()` to correlate an execution with the tool events dispatched around it. Update any subclass that overrides the constructor.

## Upgrading To 0.10 From 0.9

### Polymorphic Conversation Participants

**Likelihood Of Impact: High**

Remembered conversations now use a polymorphic participant instead of a `user_id`. The conversation tables now contain nullable `participant_type` and `participant_id` columns, and the `HasConversations` concern returns a `MorphMany` relationship.

The package's existing migration will not run again during an upgrade. Applications that have already migrated the conversation tables should create a new migration similar to the following, replacing `App\Models\User` with the model associated with the existing rows:

```php
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
$messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

Schema::table($conversationsTable, function (Blueprint $table) {
    $table->dropIndex(['user_id', 'updated_at']);
    $table->renameColumn('user_id', 'participant_id');
    $table->string('participant_type')->nullable()->after('id');
});

Schema::table($messagesTable, function (Blueprint $table) {
    $table->dropIndex('conversation_index');
    $table->dropIndex(['user_id']);
    $table->renameColumn('user_id', 'participant_id');
    $table->string('participant_type')->nullable()->after('conversation_id');
});

$participantType = (new User)->getMorphClass();

DB::table($conversationsTable)->whereNotNull('participant_id')->update(['participant_type' => $participantType]);
DB::table($messagesTable)->whereNotNull('participant_id')->update(['participant_type' => $participantType]);

Schema::table($conversationsTable, function (Blueprint $table) {
    $table->index(
        ['participant_type', 'participant_id', 'updated_at'],
        'participant_updated_at_index',
    );
});

Schema::table($messagesTable, function (Blueprint $table) {
    $table->index(
        ['conversation_id', 'participant_type', 'participant_id', 'updated_at'],
        'conversation_index',
    );

    $table->index(['participant_type', 'participant_id'], 'participant_index');
});
```

If existing rows belong to more than one model, backfill each model separately. Rows for different model types that previously shared the same `user_id` cannot be assigned automatically because the old schema did not record their model type.

Custom `ConversationStore` implementations must update their method signatures to receive the participant type before the participant ID:

```php
public function latestConversationId(
    string $participantType,
    string|int $participantId,
): ?string;

public function storeConversation(
    ?string $participantType,
    string|int|null $participantId,
    string $title,
): string;

public function storeUserMessage(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    AgentPrompt $prompt,
): string;

public function storeAssistantMessage(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    AgentPrompt $prompt,
    AgentResponse $response,
): ?string;
```

Use `forParticipant($participant)` when starting a conversation for a participant other than a user. The existing `forUser($user)` method remains available as an alias.

### New `approval_state` Column On Conversation Messages

**Likelihood Of Impact: High**

The human-in-the-loop tool approval flow records its pause and resolution details on the conversation messages table in a new nullable `TEXT` column named `approval_state`. Fresh installations receive the column through the published migration.

If you have already published and run the conversation migrations, create a new migration to add the column, then run `php artisan migrate`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'), function (Blueprint $table) {
            $table->text('approval_state')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'), function (Blueprint $table) {
            $table->dropColumn('approval_state');
        });
    }
};
```

### The `Agent` Contract Now Accepts `Decisions|string`

**Likelihood Of Impact: Low**

`Agent::prompt()`, `stream()`, `queue()`, `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()` now accept `Decisions|string` instead of `string`. A `Decisions` instance contains a map of approval decisions keyed by tool call ID and is used to resume a paused run:

```php
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

$agent->prompt(Decisions::from([
    'call_abc' => true,
    'call_def' => Decision::reject('Not permitted.'),
]));
```

No changes are needed if your agents use the `Promptable` trait. If you implement `Laravel\Ai\Contracts\Agent` directly, change the type of each `$prompt` parameter to `Decisions|string` and import `Laravel\Ai\Approvals\Decisions` to match the contract.

### The `ConversationStore` Contract Adds `storeApprovalResults()`

**Likelihood Of Impact: Low**

The `ConversationStore` interface now includes a `storeApprovalResults()` method. In addition, `storeAssistantMessage()` now returns `?string`, with `null` indicating that a resumed run produced nothing new to store:

```php
public function storeApprovalResults(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    array $toolResults,
): void;
```

No changes are needed if you use the included database store. If you bind a custom `ConversationStore`, implement `storeApprovalResults()` to merge the given results into the paused turn and throw an `ApprovalMismatchException` when no paused turn matches. Existing `storeAssistantMessage()` implementations that return `string` continue to satisfy the widened return type.

## Upgrading To 0.9 From 0.8

### Provider Options API

**Likelihood Of Impact: High**

The `providerOptions()` method on embeddings and transcription builders has been
removed in favor of `withProviderOptions()`:

```php
// Before...
Ai::embeddings('...')->providerOptions(['dimensions' => 256]);

// After...
Ai::embeddings('...')->withProviderOptions(['dimensions' => 256]);
```

The `withProviderOptions()` signature on provider tools has also changed. The
`Lab|string $provider` argument was dropped in favor of an array or closure:

```php
// Before...
$tool->withProviderOptions('openai', ['key' => 'value']);

// After...
$tool->withProviderOptions(['key' => 'value']);

// Or, to vary options per provider, pass a closure...
$tool->withProviderOptions(fn (Lab|string $provider) => match ($provider) {
    Lab::OpenAi => ['key' => 'value'],
    default => [],
});
```

### The `TextGateway` Contract Was Removed

**Likelihood Of Impact: Low**

`Laravel\Ai\Contracts\Gateway\TextGateway` has been removed. Provider gateways
now implement only `StepTextGateway`, and the multi-step API lives entirely on
the provider's `TextGenerationLoop`. Most applications are unaffected.

If you wrote or type-hinted a custom gateway, swap the contract:

```php
// Before...
use Laravel\Ai\Contracts\Gateway\TextGateway;

class MyGateway implements TextGateway { /* generateText, streamText, onToolInvocation */ }

// After...
use Laravel\Ai\Contracts\Gateway\StepTextGateway;

class MyGateway implements StepTextGateway { /* generateTextStep, generateStreamStep */ }
```

The multi-step methods you used to call on the gateway now live on the loop:

```php
// Before...
$provider->textGateway()->generateText(...);
$provider->textGateway()->onToolInvocation(...);

// After...
$provider->textGenerationLoop()->generate(...);
$provider->textGenerationLoop()->onToolInvocation(...);
```

If you implement `TextProvider` directly instead of extending the base
`Provider`, add a `textGenerationLoop(): TextGenerationLoop` method. Anything
extending `Provider` gets it for free.

### Faked Responses Now Run Through The Real Loop

**Likelihood Of Impact: Low**

`Agent::fake()` responses now flow through the same `TextGenerationLoop` as real
providers. Existing tests keep passing, but if you assert on exact messages or
streamed events, four behaviors are now more realistic:

- Faking a tool call for a tool the agent has not registered throws
  `NoSuchToolException` instead of being silently skipped. Register the tool.
- After a faked tool call, `$response->messages` includes the final assistant
  reply (one extra message). `text`, `toolCalls`, `toolResults`, and `steps`
  are unchanged.
- Faking an empty string no longer emits `TextStart` / `TextEnd` events while
  streaming.
- Faked tool calls now emit a `ToolCall` event while streaming. If you count
  streamed events, expect one extra per tool call.

### Native Anthropic Structured Outputs

**Likelihood Of Impact: Low**

Anthropic structured outputs now use the native `output_config.format` API by
default instead of the synthetic tool approach. To restore the previous
behavior, disable it in your provider configuration:

```php
'anthropic' => [
    'use_native_structured_output' => false,
],
```
