<?php

use Tests\Fixtures\Agents\AssistantAgent;

test('a streamed response is sent with headers that stop a proxy buffering it', function (): void {
    AssistantAgent::fake(['Hello world']);

    $response = (new AssistantAgent)->stream('Hi')->toResponse(request());

    expect($response->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-transform')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});
