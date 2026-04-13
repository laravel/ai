<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;

arch('nothing is ever debugged in the src directory')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->each->not->toBeUsed();

arch('globals and superglobals are not accessed from src')
    ->expect(['env', 'request', 'session'])
    ->not->toBeUsedIn('Laravel\Ai');

arch('classes in the Laravel\Ai namespace are only referenced from within Laravel\Ai')
    ->expect('Laravel\Ai')
    ->toOnlyBeUsedIn('Laravel\Ai');

arch('every contract is an interface and never contains concrete logic')
    ->expect('Laravel\Ai\Contracts')
    ->toBeInterfaces();

arch('contracts do not leak concrete implementations into consumers')
    ->expect('Laravel\Ai\Contracts')
    ->not->toUse([
        'Laravel\Ai\Providers',
        'Laravel\Ai\Gateway',
    ])
    ->ignoring([
        'Laravel\Ai\Gateway\TextGenerationOptions',
        // The Supports* capability contracts intentionally type-hint the
        // concrete provider-tool marker classes under Providers\Tools.
        'Laravel\Ai\Contracts\Providers\SupportsWebSearch',
        'Laravel\Ai\Contracts\Providers\SupportsWebFetch',
        'Laravel\Ai\Contracts\Providers\SupportsFileSearch',
    ]);

arch('every provider extends the abstract Provider base class')
    ->expect('Laravel\Ai\Providers')
    ->classes()
    ->toExtend('Laravel\Ai\Providers\Provider')
    ->ignoring([
        'Laravel\Ai\Providers\Provider',
        'Laravel\Ai\Providers\Tools',
    ]);

arch('provider classes follow the ProviderNameProvider suffix convention')
    ->expect('Laravel\Ai\Providers')
    ->classes()
    ->toHaveSuffix('Provider')
    ->ignoring([
        'Laravel\Ai\Providers\Tools',
    ]);

arch('gateway classes are only consumed from within the Laravel\Ai namespace')
    ->expect('Laravel\Ai\Gateway')
    ->classes()
    ->toOnlyBeUsedIn([
        'Laravel\Ai',
    ]);

arch('FakeAudioGateway implements the AudioGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeAudioGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\AudioGateway');

arch('FakeEmbeddingGateway implements the EmbeddingGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeEmbeddingGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\EmbeddingGateway');

arch('FakeFileGateway implements the FileGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeFileGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\FileGateway');

arch('FakeImageGateway implements the ImageGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeImageGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\ImageGateway');

arch('FakeRerankingGateway implements the RerankingGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeRerankingGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\RerankingGateway');

arch('FakeStoreGateway implements the StoreGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeStoreGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\StoreGateway');

arch('FakeTextGateway implements the TextGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeTextGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\TextGateway');

arch('FakeTranscriptionGateway implements the TranscriptionGateway contract')
    ->expect('Laravel\Ai\Gateway\FakeTranscriptionGateway')
    ->toImplement('Laravel\Ai\Contracts\Gateway\TranscriptionGateway');

arch('all concrete exceptions extend the base AiException')
    ->expect('Laravel\Ai\Exceptions')
    ->classes()
    ->toExtend('Laravel\Ai\Exceptions\AiException');

arch('exception class names end with the Exception suffix')
    ->expect('Laravel\Ai\Exceptions')
    ->classes()
    ->toHaveSuffix('Exception');

arch('agent configuration attributes are tagged with the Attribute attribute')
    ->expect('Laravel\Ai\Attributes')
    ->toHaveAttribute(Attribute::class);

arch('attribute classes do not depend on providers, gateways, or responses')
    ->expect('Laravel\Ai\Attributes')
    ->not->toUse([
        'Laravel\Ai\Providers',
        'Laravel\Ai\Gateway',
        'Laravel\Ai\Responses',
    ]);

arch('the Enums namespace only contains PHP enums')
    ->expect('Laravel\Ai\Enums')
    ->toBeEnums();

arch('everything under Concerns is a trait, never a class or interface')
    ->expect('Laravel\Ai\Concerns')
    ->toBeTraits();

arch('gateway concerns are implemented as traits as well')
    ->expect('Laravel\Ai\Gateway\Concerns')
    ->toBeTraits();

arch('response concerns are implemented as traits')
    ->expect('Laravel\Ai\Responses\Concerns')
    ->toBeTraits();

arch('job concerns are implemented as traits')
    ->expect('Laravel\Ai\Jobs\Concerns')
    ->toBeTraits();

arch('provider concerns are implemented as traits')
    ->expect('Laravel\Ai\Providers\Concerns')
    ->toBeTraits();

arch('every Event in the Events namespace is a class, never an interface or trait')
    ->expect('Laravel\Ai\Events')
    ->toBeClasses();

arch('every streaming event extends the abstract StreamEvent base class')
    ->expect('Laravel\Ai\Streaming\Events')
    ->classes()
    ->toExtend('Laravel\Ai\Streaming\Events\StreamEvent')
    ->ignoring('Laravel\Ai\Streaming\Events\StreamEvent');

arch('queueable jobs implement ShouldQueue')
    ->expect('Laravel\Ai\Jobs')
    ->classes()
    ->toImplement(ShouldQueue::class)
    ->ignoring('Laravel\Ai\Jobs\Concerns');

arch('console commands extend the Laravel Command base class')
    ->expect('Laravel\Ai\Console\Commands')
    ->classes()
    ->toExtend(Command::class);

arch('console command class names end with Command')
    ->expect('Laravel\Ai\Console\Commands')
    ->classes()
    ->toHaveSuffix('Command');

arch('the tool handler Request only flows through Tools, Gateways, and Contracts')
    ->expect('Laravel\Ai\Tools\Request')
    ->toOnlyBeUsedIn([
        'Laravel\Ai\Tools',
        'Laravel\Ai\Gateway',
        'Laravel\Ai\Contracts',
    ]);

arch('agent middleware exposes a handle method so the pipeline can invoke it')
    ->expect('Laravel\Ai\Middleware')
    ->classes()
    ->toHaveMethod('handle');

arch('prompt classes live under the Prompts namespace and stay decoupled from providers')
    ->expect('Laravel\Ai\Prompts')
    ->not->toUse('Laravel\Ai\Providers');

arch('response classes do not depend on providers or gateway internals')
    ->expect('Laravel\Ai\Responses')
    ->not->toUse([
        'Laravel\Ai\Providers',
        'Laravel\Ai\Gateway\OpenAi',
        'Laravel\Ai\Gateway\Anthropic',
        'Laravel\Ai\Gateway\Prism',
        'Laravel\Ai\Gateway\Mistral',
        'Laravel\Ai\Gateway\Gemini',
        'Laravel\Ai\Gateway\Groq',
        'Laravel\Ai\Gateway\Xai',
    ]);

arch('feature and integration tests follow the *Test suffix convention')
    ->expect('Tests\Feature')
    ->classes()
    ->toHaveSuffix('Test')
    ->ignoring([
        // Helper traits referenced from tests/Pest.php.
        'Tests\Feature\Providers\Anthropic\AnthropicHelpers',
        'Tests\Feature\Providers\Gemini\GeminiHelpers',
        'Tests\Feature\Providers\Groq\GroqHelpers',
        'Tests\Feature\Providers\Mistral\MistralHelpers',
        'Tests\Feature\Providers\OpenAi\OpenAiHelpers',
        'Tests\Feature\Providers\Xai\XaiHelpers',
        // Fixture namespaces — stub agents and tools consumed by tests.
        'Tests\Feature\Agents',
        'Tests\Feature\Tools',
    ]);

arch('tests never bundle debug helpers into a commit')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsedIn('Tests');
