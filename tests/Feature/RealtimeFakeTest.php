<?php

use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Realtime;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\RealtimeSession;

use function Laravel\Ai\agent;

test('realtime session can be faked with default response', function (): void {
    Realtime::fake();

    $session = agent(instructions: 'You are a support bot')->realtime();

    expect($session)->toBeInstanceOf(RealtimeSession::class)
        ->and($session->id())->toStartWith('sess_fake_')
        ->and($session->clientSecret())->toStartWith('ek_fake_')
        ->and($session->expiresAt())->toBeGreaterThan(time());

    Realtime::assertSessionCreated(fn (RealtimePrompt $prompt) => $prompt->contains('You are a support bot'));
});

test('realtime session can be faked with custom array responses', function (): void {
    Realtime::fake([
        [
            'id' => 'sess_custom_1',
            'client_secret' => 'ek_custom_secret_1',
            'expires_at' => 1790000000,
            'model' => 'gpt-4o-realtime-preview',
            'voice' => 'alloy',
        ],
        new RealtimeSession(
            id: 'sess_custom_2',
            clientSecret: 'ek_custom_secret_2',
            expiresAt: 1790000001,
            model: 'gpt-4o-realtime-preview',
            meta: new Meta('openai', 'gpt-4o-realtime-preview'),
            voice: 'verse',
        ),
    ]);

    $session1 = agent(instructions: 'First agent')->realtime();
    expect($session1->id())->toBe('sess_custom_1')
        ->and($session1->clientSecret())->toBe('ek_custom_secret_1');

    $session2 = agent(instructions: 'Second agent')->realtime();
    expect($session2->id())->toBe('sess_custom_2')
        ->and($session2->voice())->toBe('verse');

    Realtime::assertSessionCreated(fn (RealtimePrompt $p) => $p->contains('First agent'));
    Realtime::assertSessionCreated(fn (RealtimePrompt $p) => $p->contains('Second agent'));
    Realtime::assertSessionNotCreated(fn (RealtimePrompt $p) => $p->contains('Third agent'));
});

test('realtime session can be faked with a closure', function (): void {
    Realtime::fake(function (RealtimePrompt $prompt): array {
        return [
            'id' => 'sess_dynamic_'.$prompt->voice,
            'client_secret' => 'ek_secret_'.$prompt->voice,
            'expires_at' => 1790000000,
            'voice' => $prompt->voice,
        ];
    });

    $session = agent(instructions: 'Voice test')->realtime(voice: 'marin');

    expect($session->id())->toBe('sess_dynamic_marin')
        ->and($session->clientSecret())->toBe('ek_secret_marin')
        ->and($session->voice())->toBe('marin');
});

test('can assert no realtime sessions were created', function (): void {
    Realtime::fake();

    Realtime::assertNothingCreated();
});

test('realtime session creation can prevent stray requests', function (): void {
    Realtime::fake()->preventStrayRealtime();

    agent(instructions: 'Unfaked agent')->realtime();
})->throws(RuntimeException::class, 'Attempted realtime session creation without a fake response.');

test('realtime session serializes to array and json', function (): void {
    $session = new RealtimeSession(
        id: 'sess_123',
        clientSecret: 'ek_secret_123',
        expiresAt: 1790000000,
        model: 'gpt-4o-realtime-preview',
        meta: new Meta('openai', 'gpt-4o-realtime-preview'),
        voice: 'alloy',
        modalities: ['text', 'audio'],
    );

    expect($session->toArray())->toEqual([
        'id' => 'sess_123',
        'client_secret' => 'ek_secret_123',
        'expires_at' => 1790000000,
        'model' => 'gpt-4o-realtime-preview',
        'voice' => 'alloy',
        'modalities' => ['text', 'audio'],
    ]);

    expect(json_encode($session))->toBe(json_encode($session->toArray()));
});
