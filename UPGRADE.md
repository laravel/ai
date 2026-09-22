# Upgrade Guide

## Upgrading To 1.0 From 0.11

### Conversation Messages Now Store Steps

**Likelihood Of Impact: High**

The `tool_calls` and `tool_results` columns on the `agent_conversation_messages` table have been replaced by a single `steps` column. Each assistant message now stores one entry per model round-trip, and each tool result is stored on the tool call that produced it:

```json
[
    {"content": "", "tool_calls": [{"id": "call_1", "name": "read_file", "arguments": {}, "result": "..."}], "reasoning": "", "replay_blocks": [], "provider_tool_calls": []},
    {"content": "Done.", "tool_calls": [], "reasoning": "", "replay_blocks": [], "provider_tool_calls": []}
]
```

The `participant_index` on the same table now also includes the `agent` column.

The `approval_state` column has been replaced by a `status` column holding a `Laravel\Ai\Enums\MessageStatus` value: `completed`, `paused`, or `failed`. The reason a call is waiting on a decision is now stored on the call itself as `approval_reason`, so a stored call carrying that key without a `result` is one still pending:

```json
{"id": "call_1", "name": "delete_file", "arguments": {"path": "a"}, "approval_reason": "Destructive."}
```

The package's existing migration will not run again during an upgrade. If you have already migrated the conversation tables, create a new migration containing the code below, then run `php artisan migrate` before deploying the new version of your application. The migration adds the `steps` and `status` columns, rewrites every existing row, and drops the old columns.

A turn that is still paused waiting on a tool approval cannot be resumed once its `approval_state` is gone, so resolve or abandon any pending approvals before running the migration. A turn that is still paused keeps its history, but loses the call that was waiting:

<details>
<summary>Backfill migration</summary>

```php
<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->default(MessageStatus::Completed->value);
        });

        $this->query($table)->where('role', 'user')->update(['steps' => '[]']);

        $this->query($table)
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) use ($table) {
                foreach ($conversations as $conversation) {
                    $this->backfill($table, $conversation->conversation_id);
                }
            });

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable(false)->change();
            $blueprint->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
            $blueprint->dropIndex('participant_index');
            $blueprint->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    /**
     * Rewrite one conversation's assistant rows as steps, each result landing on the call that made it.
     */
    protected function backfill(string $table, string $conversationId): void
    {
        $rows = $this->query($table)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // A result was recorded on the row of the request that produced it, which may be a later row than its call...
        $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

        foreach ($rows as $row) {
            $meta = $this->decoded($row->meta);

            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn (array $call) => $results->has($call['id'] ?? ''))
                ->map(fn (array $call) => [
                    ...$call,
                    'result' => $results[$call['id']]['result'] ?? null,
                    ...array_filter([
                        'denied' => $results[$call['id']]['denied'] ?? false,
                        'failed' => $results[$call['id']]['failed'] ?? false,
                    ]),
                ])
                ->values()
                ->all();

            $content = (string) $row->content;

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $meta['reasoning'] ?? '')]
                : [$this->step($content, $calls, $meta['reasoning'] ?? '')];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            $this->query($table)->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    protected function query(string $table): Builder
    {
        return DB::connection($this->getConnection())->table($table);
    }
};
```

</details>

Raw queries against the conversation tables should read the `steps` column in place of `tool_calls` and `tool_results`.

On the `Laravel\Ai\Models\ConversationMessage` model, `tool_calls` and `tool_results` remain as read-only attributes, joined by a new `provider_tool_calls` attribute. Write to the `steps` attribute to modify a stored message. The `approval_state` cast has been replaced by a `status` cast to the same `MessageStatus` enum.

Reasoning and replay state have moved out of the `meta` column:

- `meta.reasoning` is now `steps[].reasoning`.
- `meta.provider_steps` and `meta.provider_content_blocks` are now `steps[].replay_blocks`, which are kept only while a turn is paused for tool approval and cleared once it completes.

A stored tool call holds the `id`, `name`, `arguments`, `result`, `result_id`, `denied`, and `failed` keys, plus `approval_reason` while a decision is pending and `thought_signature` when Gemini provides one. Provider reasoning keys such as `reasoning_id` and `reasoning_encrypted_content` are no longer stored.

The `Laravel\Ai\Storage\StoredMessage` class replaces its `$toolCalls` and `$toolResults` properties with methods, and its `$approvalState` array with a `$status` enum:

```php
// Before...
$message->toolCalls;
$message->toolResults;
$message->approvalState['pending'];

// After...
$message->toolCalls();
$message->toolResults();
$message->providerToolCalls();
array_filter($message->toolCalls(), fn (array $call) => PendingApproval::isPending($call));
```

Its constructor accepts a `steps` argument in place of `toolCalls` and `toolResults`, and `toArray()` emits `steps` and `status` keys in place of `tool_calls`, `tool_results`, and `approval_state`. Update any code that constructs a `StoredMessage` manually.

### Agent Middleware Wraps Each Generation Step

**Likelihood Of Impact: High**

Agent middleware now wraps each generation step instead of the whole run, and receives a `Laravel\Ai\PendingStep` instead of an `AgentPrompt`. A run that takes three steps invokes your middleware three times.

Update the `handle()` method of each of your middleware classes:

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

You may modify a step by creating a copy before passing it to the next middleware:

```php
public function handle(PendingStep $step, Closure $next)
{
    if (! $step->isFirstStep()) {
        $step = $step->withoutTools('SearchDocumentation');
    }

    return $next($step);
}
```

The `withModel()`, `withInstructions()`, `withMessages()`, `withTools()`, `onlyTools()`, `withoutTools()`, `withToolChoice()`, `withMaxTokens()`, and `withProviderOptions()` methods are available, along with `isFirstStep()` and the `$isFinalStep` property.

Return the `Laravel\Ai\Gateway\StepResult` returned by `$next($step)`, or return a `StepResponse` to answer the step without calling the model. Anything else throws a `LogicException`.

The `AgentPrompted`, `AgentStreamed`, and `AgentFailed` events now carry the original `AgentPrompt` passed to the provider rather than a prompt modified by run middleware. If you relied on a listener receiving the modified prompt, move that logic into the middleware itself.

### Gemini Vector Store Imports Wait For Completion

**Likelihood Of Impact: High**

Adding a file to a Gemini vector store now waits for the import to finish instead of returning as soon as it is requested:

```php
$store->addFile($fileId);
```

The returned ID is now the document name rather than the import operation name, so IDs stored by an earlier version of the package no longer match. If you persist these IDs, re-import the affected files. The call now throws a `Laravel\Ai\Exceptions\AiException` when the import fails or does not finish within five minutes, so wrap it in a `try` / `catch` if you need to handle that case.

### The AWS SDK Is No Longer Installed By Default

**Likelihood Of Impact: High**

The `aws/aws-sdk-php` package is no longer a required dependency of the package. If you use the Bedrock provider, install it in your application:

```bash
composer require aws/aws-sdk-php
```

Resolving the Bedrock provider without the SDK installed throws a `RuntimeException`.

### Token Usage Is Reported Inclusively

**Likelihood Of Impact: High**

`Usage::$promptTokens` and `Usage::$completionTokens` have been renamed to `Usage::$inputTokens` and `Usage::$outputTokens`. Update any code that reads them:

```php
// Before...
$response->usage->promptTokens;
$response->usage->completionTokens;

// After...
$response->usage->inputTokens;
$response->usage->outputTokens;
```

The new properties carry the provider's full counts. `inputTokens` now includes cached and cache-written tokens, and `outputTokens` now includes reasoning tokens. Previously, these were reported separately and excluded from the totals.

If you previously calculated input token costs by applying a single rate to `promptTokens`, calculate each category separately: apply the base rate to `uncachedInputTokens()`, the cache read rate to `cacheReadInputTokens`, and the cache write rate to `cacheWriteInputTokens`. For example:

```php
$usage = $response->usage;

$cost = $usage->uncachedInputTokens() * $baseRate
    + ($usage->cacheReadInputTokens ?? 0) * $cacheReadRate
    + ($usage->cacheWriteInputTokens ?? 0) * $cacheWriteRate;
```

`toArray()` and the JSON stored in the `usage` column of the `agent_conversation_messages` table now use the `input_tokens` and `output_tokens` keys. Rows written before the upgrade keep the old keys, so read both when reporting on historical rows.

Reported values have also changed in three places:

- Anthropic streams read the cumulative usage reported on `message_delta`, so a run using a server tool such as web search reports a higher input token count than before.
- Anthropic populates `reasoningTokens` from the thinking token breakdown rather than always reporting `0`.
- Cohere embeddings on Bedrock report the input token count returned by the API rather than always reporting `0`.

### Resumed Turns Fold Into The Message They Paused On

**Likelihood Of Impact: Medium**

Resuming a paused turn now appends the steps the resumed run made to the assistant message the turn paused on, rather than storing a second assistant message. The turn's usage is summed and its citations are merged, and `storeAssistantMessage()` returns the ID of the message it folded into.

A conversation that paused for an approval therefore holds one assistant message per turn instead of one per request. If you render a transcript or count messages, expect the resumed half of a turn to appear on the message that requested the approval.

### Failed Turns Are Recorded

**Likelihood Of Impact: Medium**

A remembered run that throws now stores the steps it completed before it died, as an assistant message with a `failed` status carrying the error message in `meta.error`. Previously the turn was lost and the conversation kept only the user message.

The turn is recorded once the run is out of providers to fail over to, so a run that fails over and then succeeds stores only the successful turn. A run that died before its first step stores nothing, unless it was resuming a paused turn, which is failed in place.

If you render a transcript or count messages, expect an assistant message where a failed run previously left none. Filter them out by status:

```php
$conversation->messages()->where('status', MessageStatus::Completed);
```

Streamed runs report their failure through a new `catch()` callback on `StreamableAgentResponse`, which receives the exception before it is rethrown.

### Last Conversations Are Scoped To The Agent

**Likelihood Of Impact: Medium**

`continueLastConversation()` now resolves the participant's last conversation *with the agent it is called on*, rather than their last conversation with any agent. An application where one user talks to several remembering agents will resume a different conversation than before:

```php
// Before... the user's newest conversation, whichever agent wrote it.
// After... the user's newest conversation with this agent.
(new SupportAgent)->continueLastConversation($user)->prompt('...');
```

The lookup is served by the `participant_index`, which now includes the `agent` column. The backfill migration above rebuilds the index; a conversation table created by the package's own migration on this version already has it.

If you relied on the old behavior, resolve the ID yourself and pass it to `continue()`.

### Gemini Uses The Interactions API

**Likelihood Of Impact: Medium**

Gemini text generation, streaming, tools, structured output, image generation, speech, and transcription now post to `v1beta/interactions` rather than `models/{model}:generateContent`. Embeddings, files, and vector stores keep their own endpoints. No configuration change is needed, as the base URL is unchanged.

Raw provider options are passed to Gemini as given, so any you send must use the Interactions names:

```php
// Before...
$agent->withProviderOptions(['thinkingConfig' => ['thinkingBudget' => 1024]]);

// After...
$agent->withProviderOptions(['thinking_level' => 'high']);
```

- `thinkingConfig` is now `thinking_level` and `thinking_summaries`.
- `toolConfig` is now `tool_choice`, inside the generation config.
- `cachedContent` no longer exists.
- `safetySettings`, `serviceTier`, and `store` are still sent beside the generation config, under their snake case names.

A `generationConfig` or `generation_config` key is still unwrapped into the generation config, so only the names inside it need to change. The [migration guide](https://ai.google.dev/gemini-api/docs/migrate-to-interactions) lists the new name for every other field.

### Text Responses Report A `TextUsage` Object

**Likelihood Of Impact: Medium**

The `Laravel\Ai\Responses\Data\Usage` class now holds only `inputTokens` and `outputTokens`. The `cacheReadInputTokens`, `cacheWriteInputTokens`, and `reasoningTokens` properties, along with the `add()` and `uncachedInputTokens()` methods, have moved to a new `Laravel\Ai\Responses\Data\TextUsage` subclass. Text, agent, step, and stream responses report a `TextUsage` object.

`StreamEnd::combineUsage()` returns a `TextUsage` as well.

No changes are needed if you only read usage from a response. If you construct a `TextResponse`, `StepResponse`, `Step`, or `StreamEnd` by hand, such as a fake in a test suite, pass a `TextUsage` instance. Note the new argument order:

```php
// Before...
use Laravel\Ai\Responses\Data\Usage;

new Usage($promptTokens, $completionTokens, $cacheWriteInputTokens, $cacheReadInputTokens, $reasoningTokens);

// After...
use Laravel\Ai\Responses\Data\TextUsage;

new TextUsage($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
```

The cache read and cache write arguments have swapped positions. The three optional counts are now `?int` and are `null` when the provider does not report them, so use the null coalescing operator when treating them as numbers.

### Usage Is Reported On Every Response

**Likelihood Of Impact: Medium**

The `EmbeddingsResponse::$tokens` property has been removed in favor of a `$usage` object, matching the other response types. Update any code that reads it:

```php
// Before...
$response->tokens;

// After...
$response->usage->inputTokens;
```

`EmbeddingsResponse::toArray()` and `jsonSerialize()` now emit a `usage` object in place of the `tokens` integer. Update any code that reads the serialized response.

`AudioResponse` and `RerankingResponse` now carry a `$usage` property as well. Each capability reports its relevant billing metrics through its usage class:

- `ImageResponse::$usage` is an `ImageUsage`, adding `imageInputTokens` and `imageOutputTokens`.
- `TranscriptionResponse::$usage` is a `TranscriptionUsage`, adding `audioSeconds`.
- `RerankingResponse::$usage` is a `RerankingUsage`, adding `searchUnits`.
- `AudioResponse::$usage` and `EmbeddingsResponse::$usage` are plain `Usage` instances.

The added counts are `null` when the provider does not report them.

No changes are needed unless you read `EmbeddingsResponse::$tokens` or construct these responses by hand. `EmbeddingsResponse`, `AudioResponse`, and `RerankingResponse` take their respective usage object as the second constructor argument, before the `Meta`. `ImageResponse` takes an `ImageUsage` as its second argument, before the `Meta`, while `TranscriptionResponse` takes a `TranscriptionUsage` as its third argument, after the text and segments and before the `Meta`.

### Stream Protocols

**Likelihood Of Impact: Medium**

Stream protocols are now objects implementing `Laravel\Ai\Streaming\Protocols\StreamProtocol` rather than a flag on the response. The `Laravel\Ai\Responses\Concerns\CanStreamUsingVercelProtocol` trait and the `toVercelProtocolArray()` method on stream events have been removed.

`usingVercelDataProtocol()` no longer accepts a boolean. Remove the first argument from any call that passes one:

```php
// Before...
$agent->stream('...')->usingVercelDataProtocol(true, 'msg_1');

// After...
$agent->stream('...')->usingVercelDataProtocol('msg_1');
```

Calls without arguments are unaffected. If you overrode `toVercelProtocolArray()` to render a custom event, implement the `StreamProtocol` interface and pass your protocol to `usingProtocol()` instead.

### Sub-Agent Activity Is Streamed

**Likelihood Of Impact: Medium**

When a streamed run calls an `AgentTool`, the sub-agent now streams instead of running to completion behind the tool call. Its events are emitted into the parent stream, and the parent emits `ToolResult` events carrying the output produced so far. These events have `preliminary` set to `true` and are followed by the final `ToolResult` for the call.

If you count events or read tool results from a stream, skip the preliminary results:

```php
foreach ($agent->stream('...') as $event) {
    if ($event instanceof ToolResult && $event->preliminary) {
        continue;
    }
}
```

The completed response's `text`, `reasoning`, `citations`, and `usage` now include the corresponding values from the sub-agent response. Review any cost calculation or text assertion made on a run that uses `AgentTool`.

### Streamed Text Is Reported Per Step

**Likelihood Of Impact: Medium**

A streamed step now emits a single `TextStart` / `TextEnd` pair. Previously, each content block emitted its own pair with a distinct message ID. `TextDelta::combine()` now separates text by step rather than by message ID, so an answer spanning several blocks is no longer split mid-sentence.

If your stream consumer opens a UI element on `TextStart` and closes it on `TextEnd`, or keys off a changing message ID, update it to expect one pair per step.

### Paused Turns Expose Their Steps

**Likelihood Of Impact: Low**

The `pausedProviderContentBlocks()` method has been removed from `AgentResponse` and `StreamedAgentResponse`. If you read the paused state of a turn, read the `steps` property instead:

```php
// Before...
$response->pausedProviderContentBlocks();

// After...
$response->steps;
```

The fourth constructor argument of `Laravel\Ai\Streaming\Events\ToolApprovalRequest` is now a `Collection` of `Laravel\Ai\Responses\Data\Step` instances instead of a `$providerContentBlocks` array. Update any code that constructs this event directly.

### Provider Content Blocks Are Now Replay Blocks

**Likelihood Of Impact: Low**

The raw provider state carried through a turn has been renamed from "provider content blocks" to "replay blocks". No changes are needed unless you construct the following objects directly or read the raw provider state from a message.

If you read or construct an `AssistantMessage`, rename the properties and constructor arguments:

```php
// Before...
$message->providerContentBlocks;
$message->providerContentBlocksProvider;

new AssistantMessage($content, $toolCalls, providerContentBlocks: $blocks, providerContentBlocksProvider: 'anthropic');

// After...
$message->replayBlocks;
$message->replayBlocksProvider;

new AssistantMessage($content, $toolCalls, replayBlocks: $blocks, replayBlocksProvider: 'anthropic');
```

If you construct a `Laravel\Ai\Gateway\StepResponse`, rename the `providerContentBlocks:` argument to `replayBlocks:`. The constructor also accepts new `reasoning:` and `providerToolCalls:` arguments, and `toArray()` emits a `replay_blocks` key.

If you construct a `Laravel\Ai\Responses\Data\Step` or `StructuredStep`, pass the two new required arguments after `$meta`:

```php
new Step($text, $toolCalls, $toolResults, $finishReason, $usage, $meta, $reasoning, $replayBlocks);
```

`Step` also accepts an optional trailing `$providerToolCalls` array, and `Step::toArray()` now emits `reasoning`, `replay_blocks`, and `provider_tool_calls` keys.

DeepSeek reasoning is now stored as a typed block rather than a raw string. For a DeepSeek turn, `AssistantMessage::$replayBlocks` is a list of `['type' => 'reasoning', 'reasoning_content' => '...']` entries.

### Reasoning Events On OpenAI And xAI

**Likelihood Of Impact: Low**

OpenAI and xAI models that stream raw reasoning text rather than a summary now emit `ReasoningStart`, `ReasoningDelta`, and `ReasoningEnd` events. If your stream consumer renders reasoning, handle these events for those providers.

`$response->reasoning` moved from `AgentResponse` to `TextResponse` and is populated on non-streamed prompts as well. It contains the combined reasoning from every step, while each step's reasoning is available on `Laravel\Ai\Responses\Data\Step`.

### Protected Provider Hooks

**Likelihood Of Impact: Low**

Several protected methods used by custom providers and gateways have changed:

- `Providers\Concerns\GeneratesText::resolveTools()` and `throwIfNotResumable()` receive an `AgentPrompt` instead of an `Agent`.
- `Providers\Concerns\GeneratesText::recordAgentFailure()` dropped its `?AgentPrompt $processedPrompt` argument, so `bool $retryable` moved from the fifth position to the fourth.
- `Providers\Concerns\GeneratesText::agentCanResumeApprovals()` was removed.
- `PendingResponses\Concerns\ResolvesProviderOptions::resolveProviderOptions()` and `Gateway\Concerns\PreparesStorableFiles::resolveProviderOptions()` are now `resolveProviderOptionsAndHeaders()`, returning the options and the headers as a tuple.
- `Gateway\RunContext::startingStep()`, `stepCompleted()`, and `stepFailed()` accept a trailing `?string $model`, and the latter two accept a nullable `StepContext`.

### The `ConversationStore` Contract

**Likelihood Of Impact: Low**

No changes are needed if you use the included database store. If you bind a custom `ConversationStore`, update the following four method signatures.

`latestConversationId()` receives the agent class name. Scope the lookup to the given agent:

```php
public function latestConversationId(
    string $participantType,
    string|int $participantId,
    string $agent,
): ?string;
```

`storeConversation()` accepts the ID the conversation should be stored under. Use the given ID when one is passed:

```php
public function storeConversation(
    ?string $participantType,
    string|int|null $participantId,
    string $title,
    ?string $id = null,
): string;
```

`storeUserMessage()` receives the agent class name and a `UserMessage` in place of an `AgentPrompt`, so a message may be stored before a provider has been resolved. Read `$message->content` and `$message->attachments` in place of `$prompt->prompt` and `$prompt->attachments`:

```php
public function storeUserMessage(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    string $agent,
    UserMessage $message,
): string;
```

`storeApprovalResults()` no longer receives the participant. Look the paused turn up by conversation alone, so a turn paused for one participant may be resolved by another. The package no longer scopes the lookup, so authorize the resuming participant in your application before passing decisions back to the agent:

```php
public function storeApprovalResults(
    string $conversationId,
    array $toolResults,
): void;
```

`storeAssistantMessage()` accepts the error a run died with as a trailing argument, so a turn that failed can be stored alongside the steps it completed. Store the turn with a `failed` status and record the message when one is passed:

```php
public function storeAssistantMessage(
    string $conversationId,
    ?string $participantType,
    string|int|null $participantId,
    AgentPrompt $prompt,
    AgentResponse $response,
    ?Throwable $exception = null,
): ?string;
```

### The `RemembersConversations` Contract Adds `continueOrStart()`

**Likelihood Of Impact: Low**

The `Laravel\Ai\Contracts\RemembersConversations` interface now includes a `continueOrStart()` method, which continues the given conversation or starts a new one when the ID is `null`:

```php
public function continueOrStart(?string $conversationId, object $as): static;
```

No changes are needed if your agents use the `Concerns\RemembersConversations` trait, which provides the method. If an agent implements the contract by hand, add the method.

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

The image, audio, and reranking methods on providers now accept provider options, and reranking also accepts a timeout:

```php
public function image(string $prompt, array $attachments = [], ?string $size = null, ?string $quality = null, ?string $model = null, ?int $timeout = null, array $providerOptions = []): ImageResponse;

public function audio(string $text, string $voice = 'default-female', ?string $instructions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): AudioResponse;

public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): RerankingResponse;
```

The corresponding `ImageGateway`, `AudioGateway`, and `RerankingGateway` methods gained the applicable `$providerOptions` and `$timeout` arguments. In addition, `Laravel\Ai\Contracts\Providers\Provider` gained a `withHeaders()` method for sending custom HTTP headers. Anything extending the base `Laravel\Ai\Providers\Provider` gets `withHeaders()` for free.

Most applications are unaffected. If you have written a custom provider or gateway, update its method signatures to match.

Reranking requests now use a 30-second timeout by default. Bedrock previously used the AWS SDK default, so a long reranking call may now time out. Raise it with the new `timeout()` method:

```php
Reranking::of($documents)->timeout(60)->rerank('...');
```

### Stream Event Constructor Signatures

**Likelihood Of Impact: Low**

The `$provider` argument of `Laravel\Ai\Streaming\Events\ProviderToolEvent` is now a required `string` rather than an optional `?string`. If you construct this event directly, pass the provider name.

### New Configuration Options

**Likelihood Of Impact: Low**

The `config/ai.php` file gained a `default_for_classification` key and a `typesafe` connection. If you have published the configuration file, add them to classify text:

```php
'default_for_classification' => 'typesafe',

'providers' => [
    'typesafe' => [
        'driver' => 'typesafe',
        'key' => env('TYPESAFE_API_KEY'),
    ],
],
```

No changes are needed if you do not classify text or have not published the configuration file.

The package also registers `Str::decide()` and `Stringable::decide()` macros. If your application defines a macro of that name on either class, rename yours to keep it.

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
