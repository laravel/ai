# Upgrade Guide

## Upgrading To 1.0 From 0.11

### Agent Middleware Wraps Each Generation Step

**Likelihood Of Impact: High**

Agent middleware now wraps each generation step instead of the whole run, and receives a `Laravel\Ai\PendingStep` instead of an `AgentPrompt`. A run that takes three steps invokes your middleware three times.

Update the `handle()` method of each middleware:

```php
// Before...
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class LogTheRun
{
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        return $next($prompt)->then(function (AgentResponse $response) {
            // ...
        });
    }
}

// After...
use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\PendingStep;

class LogTheRun
{
    public function handle(PendingStep $step, Closure $next)
    {
        return $next($step)->then(function (StepResponse $response) {
            // ...
        });
    }
}
```

A `PendingStep` may be copied with changes before it is passed on:

```php
public function handle(PendingStep $step, Closure $next)
{
    if (! $step->isFirstStep()) {
        $step = $step->withoutTools('SearchDocumentation');
    }

    return $next($step);
}
```

The `withModel()`, `withInstructions()`, `withMessages()`, `withTools()`, `onlyTools()`, `withoutTools()`, `withToolChoice()`, `withMaxTokens()`, and `withProviderOptions()` methods are available, along with `isFirstStep()` and the `isFinalStep` property.

Return the `Laravel\Ai\Gateway\StepResult` given by `$next`, or a `StepResponse` to answer the step without calling the model. Anything else throws a `LogicException`.

The `AgentPrompted`, `AgentStreamed`, and `AgentFailed` events now always carry the prompt as it was given.

### Gemini Vector Store Imports Wait For Completion

**Likelihood Of Impact: High**

Adding a file to a Gemini vector store now waits for the import to finish instead of returning as soon as it is requested:

```php
$store->addFile($fileId);
```

The returned ID is the document name rather than the import operation name, so IDs stored by an earlier version no longer match. The call also throws a `Laravel\Ai\Exceptions\AiException` when the import fails or does not finish within five minutes.

### The AWS SDK Is No Longer Installed By Default

**Likelihood Of Impact: High**

The `aws/aws-sdk-php` package is no longer a required dependency. Applications using the Bedrock provider must install it:

```bash
composer require aws/aws-sdk-php
```

Resolving the Bedrock provider without the SDK installed throws a `RuntimeException`.

### Token Usage Is Reported Inclusively

**Likelihood Of Impact: High**

`Usage::$promptTokens` and `Usage::$completionTokens` have been renamed to `Usage::$inputTokens` and `Usage::$outputTokens`, and now carry the provider's full counts. Cached, cache-written, and reasoning tokens are subsets of them rather than separate additions:

```php
// Before...
$response->usage->promptTokens;     // excluded cached tokens
$response->usage->completionTokens;

// After...
$response->usage->inputTokens;      // includes cached and cache-written tokens
$response->usage->outputTokens;     // includes reasoning tokens
$response->usage->uncachedInputTokens();
$response->usage->totalTokens();
```

`cacheReadInputTokens`, `cacheWriteInputTokens`, and `reasoningTokens` are now `?int` and are `null` when the provider reports nothing, which is distinct from a reported `0`. The constructor argument order is now `inputTokens, outputTokens, cacheReadInputTokens, cacheWriteInputTokens, reasoningTokens`, swapping the cache read and cache write positions.

Code that priced `promptTokens` at a single rate now needs three: `uncachedInputTokens()` at the base rate, `cacheReadInputTokens` at the cache read rate, and `cacheWriteInputTokens` at the cache write rate.

`toArray()` and the JSON stored in the `usage` column of `agent_conversation_messages` use the `input_tokens` and `output_tokens` keys. Rows written before the upgrade keep the old keys.

Reported values also changed in three places:

- Anthropic streams read the cumulative usage reported on `message_delta`, so a run using a server tool such as web search reports a higher input token count than before.
- Anthropic populates `reasoningTokens` from the thinking token breakdown rather than always reporting `0`.
- Cohere embeddings on Bedrock report the input token count returned by the API rather than always reporting `0`.

### Stream Protocols

**Likelihood Of Impact: Medium**

Stream protocols are now objects implementing `Laravel\Ai\Streaming\Protocols\StreamProtocol` rather than a flag on the response. The `Laravel\Ai\Responses\Concerns\CanStreamUsingVercelProtocol` trait and the `toVercelProtocolArray()` method on stream events have been removed.

`usingVercelDataProtocol()` no longer accepts a boolean:

```php
// Before...
$agent->stream('...')->usingVercelDataProtocol(true, 'msg_1');

// After...
$agent->stream('...')->usingVercelDataProtocol('msg_1');
```

Calls without arguments are unaffected. To render a custom event, pass your own protocol to `usingProtocol()`.

### Sub-Agent Activity Is Streamed

**Likelihood Of Impact: Medium**

When a streamed run calls an `AgentTool`, the sub-agent now streams instead of running to completion behind the tool call. Its events are emitted into the parent stream, and the parent emits `ToolResult` events carrying the output produced so far. These have `preliminary` set to `true` and are followed by the final `ToolResult` for the call.

Skip them when counting events or reading results:

```php
foreach ($agent->stream('...') as $event) {
    if ($event instanceof ToolResult && $event->preliminary) {
        continue;
    }
}
```

The completed response's `text`, `reasoning`, `citations`, and `usage` now include the sub-agent's. Review any cost calculation or text assertion made on a run that uses `AgentTool`.

### Streamed Text Is Reported Per Step

**Likelihood Of Impact: Medium**

A streamed step now emits a single `TextStart` / `TextEnd` pair rather than one pair per content block, each with its own message ID. `TextDelta::combine()` separates text by step rather than by message ID to match, so an answer spanning several blocks is no longer split mid-sentence.

Update any consumer that opens a UI block on `TextStart` and closes it on `TextEnd`, or that keys off a changing message ID.

### Reasoning Events On OpenAI And xAI

**Likelihood Of Impact: Low**

OpenAI and xAI models that stream raw reasoning text rather than a summary now emit `ReasoningStart`, `ReasoningDelta`, and `ReasoningEnd` events. Handle the new events in any stream consumer that renders reasoning.

`$response->reasoning` moved from `AgentResponse` to `TextResponse` and is populated on non-streamed prompts as well, joining the reasoning of every step. It is also carried per step on `Laravel\Ai\Responses\Data\Step`.

### Protected Provider Hooks

**Likelihood Of Impact: Low**

Several protected methods changed on the classes a custom provider or gateway extends:

- `Providers\Concerns\GeneratesText::resolveTools()` and `throwIfNotResumable()` receive an `AgentPrompt` instead of an `Agent`.
- `Providers\Concerns\GeneratesText::recordAgentFailure()` dropped its `?AgentPrompt $processedPrompt` argument, so `bool $retryable` moved from the fifth position to the fourth.
- `Providers\Concerns\GeneratesText::agentCanResumeApprovals()` was removed.
- `PendingResponses\Concerns\ResolvesProviderOptions::resolveProviderOptions()` and `Gateway\Concerns\PreparesStorableFiles::resolveProviderOptions()` are now `resolveProviderOptionsAndHeaders()`, returning the options and the headers as a tuple.
- `Gateway\RunContext::startingStep()`, `stepCompleted()`, and `stepFailed()` accept a trailing `?string $model`, and the latter two accept a nullable `StepContext`.

### The `ConversationStore` Contract

**Likelihood Of Impact: Low**

`latestConversationId()` receives the agent class name, and `storeConversation()` accepts the ID the conversation should be stored under:

```php
public function latestConversationId(
    string $participantType,
    string|int $participantId,
    string $agent,
): ?string;

public function storeConversation(
    ?string $participantType,
    string|int|null $participantId,
    string $title,
    ?string $id = null,
): string;
```

`storeUserMessage()` receives the agent class name and a `UserMessage` in place of an `AgentPrompt`, so a message may be stored before a provider has been resolved:

```php
public function storeUserMessage(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    string $agent,
    UserMessage $message,
): string;
```

No changes are needed if you use the included database store. If you bind a custom `ConversationStore`, update all three signatures, scope `latestConversationId()` to the given agent, use the given ID when one is passed, and read `$message->content` and `$message->attachments` in place of `$prompt->prompt` and `$prompt->attachments`. `storeAssistantMessage()` is unchanged.

### The `RemembersConversations` Contract Adds `continueOrStart()`

**Likelihood Of Impact: Low**

The `Laravel\Ai\Contracts\RemembersConversations` interface now includes a `continueOrStart()` method, which continues the given conversation or starts a new one when the ID is `null`:

```php
public function continueOrStart(?string $conversationId, object $as): static;
```

No changes are needed if your agents use the `Concerns\RemembersConversations` trait, which provides the method. Add it to any agent that implements the contract by hand.

### The `Agent` Contract Accepts More Input Types

**Likelihood Of Impact: Low**

`Agent::prompt()`, `stream()`, `queue()`, `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()` now accept `AgentInput|UserMessage|Decisions|string` instead of `Decisions|string`, so a chat request may be handed to the agent directly:

```php
$chat = Vercel::chat($request);

$agent->withMessages($chat->history())->stream($chat);
```

No changes are needed if your agents use the `Promptable` trait. If you implement `Laravel\Ai\Contracts\Agent` directly, widen the type of each `$prompt` parameter to match the contract.

### Provider And Gateway Signatures

**Likelihood Of Impact: Low**

The image, audio, and reranking methods accept provider options, and reranking also accepts a timeout:

```php
public function image(string $prompt, array $attachments = [], ?string $size = null, ?string $quality = null, ?string $model = null, ?int $timeout = null, array $providerOptions = []): ImageResponse;

public function audio(string $text, ?string $voice = null, ?string $instructions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): AudioResponse;

public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): RerankingResponse;
```

The matching `ImageGateway`, `AudioGateway`, and `RerankingGateway` methods gained the same arguments, and `Laravel\Ai\Contracts\Providers\Provider` gained a `withHeaders()` method used to send custom HTTP headers. Anything extending the base `Laravel\Ai\Providers\Provider` gets `withHeaders()` for free.

Reranking requests now use a 30 second timeout by default. Bedrock previously used the AWS SDK default, so a long reranking call may now time out. Raise it with the new `timeout()` method:

```php
Reranking::of($documents)->timeout(60)->rerank('...');
```

Most applications are unaffected. Update any custom provider or gateway to match the new signatures.

### Stream Event Constructor Signatures

**Likelihood Of Impact: Low**

The `$provider` argument of `Laravel\Ai\Streaming\Events\ProviderToolEvent` is now a required `string` rather than an optional `?string`.

`Laravel\Ai\Streaming\Events\ToolApprovalRequest` takes a `$steps` array of every assistant step in the paused turn as its fourth argument, moving `$providerContentBlocks` to fifth. The same steps are available from `pausedSteps()` on the response.

No changes are needed unless you construct these events directly.

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
