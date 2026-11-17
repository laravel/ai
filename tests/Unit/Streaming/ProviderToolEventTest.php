<?php

use Laravel\Ai\Streaming\Events\ProviderToolEvent;

test('a provider tool event includes the invocation it belongs to', function (): void {
    $event = (new ProviderToolEvent(
        'event-1',
        'search-1',
        'web_search_call',
        ['query' => 'Laravel'],
        'in_progress',
        100,
        'openai',
    ))->withInvocationId('invocation-1');

    expect($event->toArray())->toBe([
        'id' => 'event-1',
        'invocation_id' => 'invocation-1',
        'item_id' => 'search-1',
        'type' => 'web_search_call',
        'data' => ['query' => 'Laravel'],
        'status' => 'in_progress',
        'timestamp' => 100,
        'provider' => 'openai',
    ]);
});
