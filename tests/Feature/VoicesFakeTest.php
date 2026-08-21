<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\Data\Voice;
use PHPUnit\Framework\AssertionFailedError;

test('fake voices returns default voices without touching the network', function (): void {
    Http::fake(fn () => throw new RuntimeException('Network should not be hit.'));

    Audio::fakeVoices();

    $response = Audio::voices(provider: 'eleven');

    expect($response)->toHaveCount(2)
        ->and($response->first()->gender)->toBe('female')
        ->and($response->meta->provider)->toBe('eleven');

    Audio::assertVoicesListed('eleven');
});

test('fake voices returns the given voices', function (): void {
    Audio::fakeVoices([
        new Voice('custom-voice', 'Custom Voice', 'female', ['nl']),
    ]);

    $response = Audio::voices(provider: 'mistral');

    expect($response)->toHaveCount(1)
        ->and($response->first()->id)->toBe('custom-voice');

    Audio::assertVoicesListed(fn (string $provider): bool => $provider === 'mistral');
});

test('fake voices can resolve voices via closure', function (): void {
    Audio::fakeVoices(fn ($provider): array => [
        new Voice("voice-for-{$provider->name()}", 'Dynamic Voice'),
    ]);

    expect(Audio::voices(provider: 'eleven')->first()->id)->toBe('voice-for-eleven');
});

test('assert no voices listed passes without listings and fails after one', function (): void {
    Audio::fakeVoices();

    Audio::assertNoVoicesListed();

    Audio::voices(provider: 'eleven');

    expect(fn () => Audio::assertNoVoicesListed())->toThrow(AssertionFailedError::class);
});

test('fake voices with an explicit empty catalogue stays empty', function (): void {
    Audio::fakeVoices(fn (): array => []);

    expect(Audio::voices(provider: 'eleven'))->toHaveCount(0);
});

test('voices resolves the default audio provider when none is given', function (): void {
    config(['ai.default_for_audio' => 'eleven']);

    Audio::fakeVoices();

    expect(Audio::voices()->meta->provider)->toBe('eleven');

    Audio::assertVoicesListed('eleven');
});

test('assert voices not listed passes for other providers and fails for the listed one', function (): void {
    Audio::fakeVoices();

    Audio::voices(provider: 'eleven');

    Audio::assertVoicesNotListed('mistral');

    expect(fn () => Audio::assertVoicesNotListed('eleven'))->toThrow(AssertionFailedError::class);
});

test('voices throws for providers that do not support voice listing', function (): void {
    Audio::voices(provider: 'openai');
})->throws(LogicException::class, 'does not support voice listing');
