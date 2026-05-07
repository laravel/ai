<?php

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

function callGenerateTitle(RememberConversation $middleware, string $prompt): string
{
    $method = new ReflectionMethod($middleware, 'generateTitle');

    return $method->invoke($middleware, $prompt);
}

test('generateTitle uses prompt text when conversation title generation is disabled', function () {
    config(['ai.conversations.generate_title' => false]);

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldNotReceive('textGateway');
    $provider->shouldNotReceive('cheapestTextModel');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $title = callGenerateTitle($middleware, str_repeat('word ', 30));

    expect($title)->toBeString()
        ->and(mb_strlen($title))->toBeLessThanOrEqual(50);
});

test('generateTitle falls back to prompt text when provider title generation fails', function () {
    config(['ai.conversations.generate_title' => true]);

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andThrow(new RuntimeException('Provider unavailable'));

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->once()->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->once()->andReturn('cheap-model');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $prompt = str_repeat('hello world ', 20);
    $title = callGenerateTitle($middleware, $prompt);

    expect($title)->toBeString()
        ->and(mb_strlen($title))->toBeLessThanOrEqual(100);
});

test('generateTitle returns provider generated title when enabled', function () {
    config(['ai.conversations.generate_title' => true]);

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andReturn(
        new TextResponse(
            'Provider generated title',
            new Usage,
            new Meta('test', 'cheap-model'),
        )
    );

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->once()->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->once()->andReturn('cheap-model');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $title = callGenerateTitle($middleware, 'How do I optimize this Laravel query?');

    expect($title)->toBe('Provider generated title');
});
