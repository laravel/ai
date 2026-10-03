<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\AudioFailed;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\ImageFailed;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\RerankingFailed;
use Laravel\Ai\Events\TranscriptionFailed;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking as RerankingOperation;
use Laravel\Ai\Transcription;

test('non-agent failures dispatch an event and rethrow the original exception', function (Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void {
    Event::fake();

    $exception = new RuntimeException('Provider failed.');
    $fake(fn () => throw $exception);

    $caught = null;

    try {
        $invoke();
    } catch (Throwable $e) {
        $caught = $e;
    }

    Event::assertDispatchedTimes($startingEvent, 1);
    Event::assertDispatchedTimes($failureEvent, 1);

    $started = Event::dispatched($startingEvent)->first()[0];
    $failed = Event::dispatched($failureEvent)->first()[0];

    expect($caught)->toBe($exception)
        ->and($failed->invocationId)->toBe($started->invocationId)
        ->and($failed->provider)->toBe($started->provider)
        ->and($failed->model)->toBe($started->model)
        ->and($failed->prompt)->toBe($started->prompt)
        ->and($failed->exception)->toBe($exception);
})->with('non-agent operations');

test('successful non-agent operations do not dispatch failure events', function (Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void {
    Event::fake();

    $fake([]);
    $invoke();

    Event::assertDispatchedTimes($startingEvent, 1);
    Event::assertNotDispatched($failureEvent);
})->with('non-agent operations');

test('a throwing success listener does not report the operation as failed', function (): void {
    Embeddings::fake();

    $failed = [];

    Event::listen(EmbeddingsFailed::class, function (EmbeddingsFailed $event) use (&$failed): void {
        $failed[] = $event;
    });

    Event::listen(EmbeddingsGenerated::class, fn () => throw new RuntimeException('Listener failed.'));

    expect(fn () => Embeddings::for(['Hello world'])->generate())->toThrow(RuntimeException::class, 'Listener failed.')
        ->and($failed)->toBeEmpty();
});

test('a recovered failover reports only the attempt that failed', function (): void {
    Event::fake();

    $attempts = 0;

    Embeddings::fake(function () use (&$attempts): array {
        if ($attempts++ === 0) {
            throw RateLimitedException::forProvider('openai');
        }

        return [[0.1, 0.2]];
    });

    $response = Embeddings::for(['Hello world'])->generate(['openai', 'gemini']);

    [$failedAttempt, $recoveredAttempt] = Event::dispatched(GeneratingEmbeddings::class)
        ->map(fn (array $event): string => $event[0]->invocationId)
        ->all();

    expect($response->embeddings)->toBe([[0.1, 0.2]]);

    Event::assertDispatchedTimes(EmbeddingsFailed::class, 1);
    Event::assertDispatched(EmbeddingsFailed::class, fn (EmbeddingsFailed $event): bool => $event->invocationId === $failedAttempt);
    Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event): bool => $event->invocationId === $recoveredAttempt);
});

dataset('non-agent operations', [
    'embeddings' => [
        fn ($responses) => Embeddings::fake($responses),
        fn () => Embeddings::for(['Hello world'])->generate(),
        GeneratingEmbeddings::class,
        EmbeddingsFailed::class,
    ],
    'image' => [
        fn ($responses) => Image::fake($responses),
        fn () => Image::of('A sunset')->generate(),
        GeneratingImage::class,
        ImageFailed::class,
    ],
    'audio' => [
        fn ($responses) => Audio::fake($responses),
        fn () => Audio::of('Hello world')->generate(),
        GeneratingAudio::class,
        AudioFailed::class,
    ],
    'transcription' => [
        fn ($responses) => Transcription::fake($responses),
        fn () => Transcription::of(base64_encode('audio'))->generate(),
        GeneratingTranscription::class,
        TranscriptionFailed::class,
    ],
    'reranking' => [
        fn ($responses) => RerankingOperation::fake($responses),
        fn () => RerankingOperation::of(['Laravel is a framework'])->rerank('What is Laravel?'),
        Reranking::class,
        RerankingFailed::class,
    ],
    'classification' => [
        fn ($responses) => Classification::fake($responses),
        fn () => Classification::of('Urgent request')->question('urgent', new Boolean('Is it urgent?'))->classify(),
        Classifying::class,
        ClassificationFailed::class,
    ],
]);
