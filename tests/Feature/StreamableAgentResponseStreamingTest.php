<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\TestCase;

class StreamableAgentResponseStreamingTest extends TestCase
{
    public function test_streamable_agent_response_streams_under_octane(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = '1';

        try {
            $invocationId = 'invocation-1';

            $response = new StreamableAgentResponse(
                $invocationId,
                function () use ($invocationId): iterable {
                    yield (new TextDelta('event-1', 'message-1', 'Hello', 123))
                        ->withInvocationId($invocationId);

                    yield (new StreamEnd('event-2', 'stop', new Usage, 124))
                        ->withInvocationId($invocationId);
                },
                new Meta('test', 'model'),
            );

            $streamedResponse = $response->toResponse(request());

            $testResponse = TestResponse::fromBaseResponse($streamedResponse);

            $content = $testResponse->streamedContent();

            $this->assertStringContainsString(
                'data: {"id":"event-1","invocation_id":"invocation-1","type":"text_delta","message_id":"message-1","delta":"Hello","timestamp":123}',
                $content
            );

            $this->assertStringContainsString('data: [DONE]', $content);
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }
    }

    public function test_streamable_agent_response_streams_vercel_protocol_under_octane(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = '1';

        try {
            $invocationId = 'invocation-2';

            $response = (new StreamableAgentResponse(
                $invocationId,
                function () use ($invocationId): iterable {
                    yield (new StreamStart('stream-1', 'test', 'model', 123))
                        ->withInvocationId($invocationId);

                    yield (new TextDelta('event-1', 'message-1', 'Hello', 124))
                        ->withInvocationId($invocationId);

                    yield (new StreamEnd('event-2', 'stop', new Usage, 125))
                        ->withInvocationId($invocationId);
                },
                new Meta('test', 'model'),
            ))->usingVercelDataProtocol();

            $streamedResponse = $response->toResponse(request());
            $testResponse = TestResponse::fromBaseResponse($streamedResponse);
            $content = $testResponse->streamedContent();

            $this->assertStringContainsString(
                'data: {"type":"start","messageId":"stream-1"}',
                $content
            );

            $this->assertStringContainsString(
                'data: {"type":"text-delta","id":"message-1","delta":"Hello"}',
                $content
            );

            $this->assertStringContainsString('data: {"type":"finish"}', $content);
            $this->assertStringContainsString('data: [DONE]', $content);
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }
    }
}
