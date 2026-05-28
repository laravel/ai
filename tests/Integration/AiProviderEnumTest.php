<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;
use Tests\Fixtures\Agents\AssistantAgent;

test('agent prompt accepts ai provider enum', function () {
    requiresApiKey('GROQ_API_KEY');

    Event::fake();

    $response = (new AssistantAgent)->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue()
        ->and($response->meta->provider)->toEqual('groq');

    Event::assertDispatched(AgentPrompted::class);
});

test('agent stream accepts ai provider enum', function () {
    requiresApiKey('GROQ_API_KEY');

    Event::fake();

    $response = (new AssistantAgent)->stream(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    );

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect(collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty())->toBeTrue()
        ->and(str_contains($response->text, 'Laravel'))->toBeTrue();

    Event::assertDispatched(AgentStreamed::class);
});

test('agent queue accepts ai provider enum', function () {
    requiresApiKey('GROQ_API_KEY');

    (new AssistantAgent)->queue(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.enum_queue_response'] = $response;
    });

    $response = $_ENV['__testing.enum_queue_response'];

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();

    unset($_ENV['__testing.enum_queue_response']);
});

test('agent prompt accepts array of ai provider enum values for failover', function () {
    requiresApiKey('GROQ_API_KEY');

    $response = (new AssistantAgent)->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: [Lab::Groq],
        model: 'openai/gpt-oss-20b',
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue()
        ->and($response->meta->provider)->toEqual('groq');
});

test('agent prompt accepts qianfan ai provider enum with fake gateway', function () {
    config(['ai.providers.qianfan' => [
        'driver' => 'qianfan',
        'key' => 'test-key',
    ]]);

    AssistantAgent::fake(['Laravel']);

    $response = (new AssistantAgent)->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: Lab::Qianfan,
        model: 'ernie-4.5-turbo-128k',
    );

    expect($response->text)->toBe('Laravel')
        ->and($response->meta->provider)->toEqual('qianfan');
});

test('embeddings generate accepts ai provider enum', function () {
    requiresApiKey('OPENAI_API_KEY');

    Event::fake();

    $response = Embeddings::for(['I love to watch Star Trek.'])
        ->generate(provider: Lab::OpenAI);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(1536)
        ->and($response->meta->provider)->toEqual('openai');

    Event::assertDispatched(GeneratingEmbeddings::class);
    Event::assertDispatched(EmbeddingsGenerated::class);
});

test('audio generate accepts ai provider enum', function () {
    requiresApiKey('OPENAI_API_KEY');

    $response = Audio::of('Hello there! How are you today?')
        ->generate(provider: Lab::OpenAI);

    expect($response->meta->provider)->toEqual('openai');
});

test('transcription generate accepts ai provider enum', function () {
    requiresApiKey('OPENAI_API_KEY');

    $audio = Audio::of('Hello there! How are you today?')->generate();

    $transcription = Transcription::of($audio->audio)
        ->generate(provider: Lab::OpenAI);

    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue();
});
