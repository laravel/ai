# Upgrade Guide

## Upgrading To 1.0 From 0.9

### New `approval_state` Column On Conversation Messages

**Likelihood Of Impact: High**

Human-in-the-loop tool approval records its pause and resolution state on the
conversation messages table via a new nullable `TEXT` column, `approval_state`.
Fresh installs get the column from the published migration.

If you have already published and run the conversation migrations, add the
column with a new migration and run `php artisan migrate`:

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

### The `Agent` Contract Now Accepts `array|string`

**Likelihood Of Impact: Low**

`Agent::prompt()`, `stream()`, `queue()`, `broadcast()`, `broadcastNow()`, and
`broadcastOnQueue()` now accept `array|string` instead of `string`. The array is
a tool-call-id-keyed map of approval decisions passed when resuming a paused run.

Nothing to do if your agents use the `Promptable` trait. If you implement
`Laravel\Ai\Contracts\Agent` directly, widen those prompt parameters to
`array|string` to match the contract.

### The `ConversationStore` Contract Gains `storeApprovalResults()`

**Likelihood Of Impact: Low**

The `ConversationStore` interface adds a `storeApprovalResults()` method, and
`storeAssistantMessage()` now returns `?string` — `null` when a resume produced
nothing new to store:

```php
public function storeApprovalResults(
    string $conversationId,
    string|int|null $participantId,
    array $toolResults,
): void;
```

Nothing to do if you use the shipped database store. If you bind a custom
`ConversationStore`, implement `storeApprovalResults()` to merge the given
results into the paused turn and throw `ApprovalMismatchException` when no
paused turn matches. Existing `storeAssistantMessage()` implementations that
return `string` continue to satisfy the widened return type.

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
