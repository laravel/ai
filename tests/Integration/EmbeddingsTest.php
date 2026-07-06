<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\EmbeddingsResponse;

test('embeddings can be generated', function (string $provider, string $apiKey, int $dimensions) {
    requiresApiKey($apiKey);

    Event::fake();

    $response = Embeddings::for(['I love to watch Star Trek.'])->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount($dimensions)
        ->and($response->meta->provider)->toEqual($provider);

    Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $event->prompt->timeout === 30);
})->with('embedding-providers');

test('embeddings can be generated with custom dimensions', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $response = Embeddings::for(['test text'])
        ->dimensions(256)
        ->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(256);
})->with('embedding-providers');

test('queued embeddings with closure provider options run end-to-end through a real queue driver', function (string $provider, string $apiKey, int $dimensions) {
    requiresApiKey($apiKey);

    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],
    ]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Event::fake([EmbeddingsGenerated::class]);

    Embeddings::for(['I love to watch Star Trek.'])
        ->withProviderOptions(fn (Provider $resolved) => match ($resolved->driver()) {
            'voyageai' => ['input_type' => 'query'],
            default => [],
        })
        ->queue(provider: $provider);

    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('jobs')->count())->toBe(0);

    Event::assertDispatched(
        EmbeddingsGenerated::class,
        fn (EmbeddingsGenerated $event) => $event->response->meta->provider === $provider
            && count($event->response->embeddings[0]) === $dimensions,
    );
})->with('embedding-providers');
