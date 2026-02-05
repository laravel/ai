---
name: developing-with-ai-sdk
description: Builds AI agents, generates text and chat responses, produces images, synthesizes audio, transcribes speech, generates vector embeddings, reranks documents, and manages files and vector stores using the Laravel AI SDK (laravel/ai). Supports structured output, streaming, tools, conversation memory, middleware, queueing, broadcasting, and provider failover. Use when building, editing, updating, debugging, or testing any AI functionality, including agents, LLMs, chatbots, text generation, image generation, audio, transcription, embeddings, RAG, similarity search, vector stores, prompting, structured output, or any AI provider (OpenAI, Anthropic, Gemini, Cohere, Groq, xAI, ElevenLabs, Jina, OpenRouter).
---

# Developing with the Laravel AI SDK

The Laravel AI SDK (`laravel/ai`) is the official AI package for Laravel, providing a unified API for agents, images, audio, transcription, embeddings, reranking, vector stores, and file management across multiple AI providers.

## Searching the Documentation

This package is new. Always search the documentation before implementing any feature. Never guess at APIs — the documentation is the single source of truth.

- Use broad, simple queries that match the documentation section headings below.
- Do not add package names to queries — package information is shared automatically. Use `test agent fake`, not `laravel ai test agent fake`.
- Run multiple queries at once — the most relevant results are returned first.

### Documentation Sections

Use these section headings as query terms for accurate results:

- Introduction, Installation, Configuration, Provider Support
- Agents: Prompting, Conversation Context, Structured Output, Attachments, Streaming, Broadcasting, Queueing, Tools, Provider Tools, Middleware, Anonymous Agents, Agent Configuration
- Images
- Audio (TTS)
- Transcription (STT)
- Embeddings: Querying Embeddings, Caching Embeddings
- Reranking
- Files
- Vector Stores: Adding Files to Stores
- Failover
- Testing: Agents, Images, Audio, Transcriptions, Embeddings, Reranking, Files, Vector Stores
- Events

## Decision Workflow

Determine the right entry point before writing code:

Text generation or chat? → Agent class with `Promptable` trait
Chat with conversation history? → Agent + `Conversational` interface (manual) or `RemembersConversations` trait (automatic)
Structured JSON output? → Agent + `HasStructuredOutput` interface
Image generation? → `Image::of()->generate()`
Audio synthesis? → `Audio::of()->generate()`
Transcription? → `Transcription::fromPath()->generate()`
Embeddings? → `Embeddings::for()->generate()`
Reranking? → `Reranking::of()->rerank()`
File storage? → `Document::fromPath()->put()`
Vector stores? → `Stores::create()`

## Basic Usage Examples

### Agents

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class SalesCoach implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a sales coach.';
    }
}

// Prompting
$response = (new SalesCoach)->prompt('Analyze this transcript...');
echo $response->text;

// Streaming (returns SSE response from a route)
return (new SalesCoach)->stream('Analyze this transcript...');

// Queueing
(new SalesCoach)->queue('Analyze this transcript...')
    ->then(fn ($response) => /* ... */);

// Anonymous agents
use function Laravel\Ai\{agent};

$response = agent(instructions: 'You are a helpful assistant.')->prompt('Hello');
```

### Conversation Context

Manual conversation history via the `Conversational` interface:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class SalesCoach implements Agent, Conversational
{
    use Promptable;

    public function __construct(public User $user) {}

    public function instructions(): string { return 'You are a sales coach.'; }

    public function messages(): iterable
    {
        return History::where('user_id', $this->user->id)
            ->latest()->limit(50)->get()->reverse()
            ->map(fn ($m) => new Message($m->role, $m->content))
            ->all();
    }
}
```

Automatic conversation persistence via the `RemembersConversations` trait:

```php
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class SalesCoach implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string { return 'You are a sales coach.'; }
}

// Start a new conversation
$response = (new SalesCoach)->forUser($user)->prompt('Hello!');
$conversationId = $response->conversationId;

// Continue an existing conversation
$response = (new SalesCoach)->continue($conversationId, as: $user)->prompt('Tell me more.');
```

### Structured Output

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class Reviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string { return 'Review and score content.'; }

    public function schema(JsonSchema $schema): array
    {
        return [
            'feedback' => $schema->string()->required(),
            'score' => $schema->integer()->min(1)->max(10)->required(),
        ];
    }
}

$response = (new Reviewer)->prompt('Review this...');
echo $response['score']; // Access like an array
```

### Images

```php
use Laravel\Ai\Image;

$image = Image::of('A sunset over mountains')
    ->landscape()
    ->quality('high')
    ->generate();

$path = $image->store(); // Store to default disk
```

### Audio

```php
use Laravel\Ai\Audio;

$audio = Audio::of('Hello from Laravel.')
    ->female()
    ->instructions('Speak warmly')
    ->generate();

$path = $audio->store();
```

### Transcription

```php
use Laravel\Ai\Transcription;

$transcript = Transcription::fromStorage('audio.mp3')
    ->diarize()
    ->generate();

echo (string) $transcript;
```

### Embeddings

```php
use Laravel\Ai\Embeddings;
use Illuminate\Support\Str;

$response = Embeddings::for(['Text one', 'Text two'])
    ->dimensions(1536)
    ->cache()
    ->generate();

// Single string via Stringable
$embedding = Str::of('Napa Valley has great wine.')->toEmbeddings();
```

### Reranking

```php
use Laravel\Ai\Reranking;

$response = Reranking::of(['Django is Python.', 'Laravel is PHP.', 'React is JS.'])
    ->limit(5)
    ->rerank('PHP frameworks');

$response->first()->document; // "Laravel is PHP."
```

### Files and Vector Stores

```php
use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;

// Store a file with the provider
$file = Document::fromPath('/path/to/doc.pdf')->put();

// Create a vector store and add files
$store = Stores::create('Knowledge Base');
$store->add($file->id);
$store->add(Document::fromStorage('manual.pdf')); // Store + add in one step
```

## Agent Configuration

### PHP Attributes

```php
use Laravel\Ai\Attributes\{Provider, MaxSteps, MaxTokens, Temperature, Timeout};

#[Provider('anthropic')]
#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[Timeout(120)]
class MyAgent implements Agent
{
    use Promptable;
    // ...
}
```

The `#[UseCheapestModel]` and `#[UseSmartestModel]` attributes are also available for automatic model selection.

### Tools

Implement the `HasTools` interface and scaffold tools with `php artisan make:tool`:

```php
use Laravel\Ai\Contracts\HasTools;

class MyAgent implements Agent, HasTools
{
    use Promptable;

    public function tools(): iterable
    {
        return [new MyCustomTool];
    }
}
```

### Provider Tools

```php
use Laravel\Ai\Providers\Tools\{WebSearch, WebFetch, FileSearch};

public function tools(): iterable
{
    return [
        (new WebSearch)->max(5)->allow(['laravel.com']),
        new WebFetch,
        new FileSearch(stores: ['store_id']),
    ];
}
```

### Conversation Memory

```php
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;

class ChatBot implements Agent, Conversational
{
    use Promptable, RemembersConversations;
    // ...
}

$response = (new ChatBot)->forUser($user)->prompt('Hello!');
$response = (new ChatBot)->continue($conversationId, as: $user)->prompt('More...');
```

### Failover

```php
$response = (new MyAgent)->prompt('Hello', provider: ['openai', 'anthropic']);
```

## Testing and Faking

Each capability supports `fake()` with assertions:

```php
use App\Ai\Agents\SalesCoach;
use Laravel\Ai\{Image, Audio, Transcription, Embeddings, Reranking, Files, Stores};

// Agents
SalesCoach::fake(['Response 1', 'Response 2']);
SalesCoach::assertPrompted('query');
SalesCoach::assertNotPrompted('query');
SalesCoach::assertNeverPrompted();
SalesCoach::fake()->preventStrayPrompts();

// Images
Image::fake();
Image::assertGenerated(fn ($prompt) => $prompt->contains('sunset'));
Image::assertNothingGenerated();

// Audio
Audio::fake();
Audio::assertGenerated(fn ($prompt) => $prompt->contains('Hello'));

// Transcription
Transcription::fake(['Transcribed text.']);
Transcription::assertGenerated(fn ($prompt) => $prompt->isDiarized());

// Embeddings
Embeddings::fake();
Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Laravel'));

// Reranking
Reranking::fake();
Reranking::assertReranked(fn ($prompt) => $prompt->contains('PHP'));

// Files
Files::fake();
Files::assertStored(fn ($file) => $file->mimeType() === 'text/plain');

// Stores
Stores::fake();
Stores::assertCreated('Knowledge Base');
$store = Stores::get('id');
$store->assertAdded('file_id');
```

## Key Patterns

- Namespace: `Laravel\Ai\`
- Package: `composer require laravel/ai`
- Agent pattern: Implement the `Agent` interface and use the `Promptable` trait
- Optional interfaces: `HasTools`, `HasMiddleware`, `HasStructuredOutput`, `Conversational`
- Entry-point classes: `Image`, `Audio`, `Transcription`, `Embeddings`, `Reranking`, `Stores`
- Artisan commands: `php artisan make:agent`, `php artisan make:tool`
- Global helper: `agent()` for anonymous agents

## Common Pitfalls

### Wrong Namespace

The namespace is `Laravel\Ai`, not `Illuminate\Ai` or `Laravel\AI`.

```php
// Correct
use Laravel\Ai\Image;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

// Wrong — these do not exist
use Illuminate\Ai\Image;
use Laravel\AI\Agent;
```

### Unsupported Provider Capability

Calling a capability not supported by a provider throws a `LogicException`. Refer to the provider support table below.

### Never Use Prism Directly

Use agents and entry-point classes (`Image`, `Audio`, etc.) — not `Prism::text()` directly. The AI SDK wraps Prism internally.

## Provider Support

| Provider   | Text | Image | Audio | STT | Embeddings | Reranking | Files | Stores |
| ---------- | ---- | ----- | ----- | --- | ---------- | --------- | ----- | ------ |
| OpenAI     | Y    | Y     | Y     | Y   | Y          | -         | Y     | Y      |
| Anthropic  | Y    | -     | -     | -   | -          | -         | Y     | -      |
| Gemini     | Y    | Y     | -     | -   | Y          | -         | Y     | Y      |
| xAI        | Y    | Y     | -     | -   | -          | -         | -     | -      |
| Groq       | Y    | -     | -     | -   | -          | -         | -     | -      |
| OpenRouter | Y    | -     | -     | -   | -          | -         | -     | -      |
| ElevenLabs | -    | -     | Y     | Y   | -          | -         | -     | -      |
| Cohere     | -    | -     | -     | -   | Y          | Y         | -     | -      |
| Jina       | -    | -     | -     | -   | Y          | Y         | -     | -      |