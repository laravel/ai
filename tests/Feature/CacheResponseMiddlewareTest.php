<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Middleware\CacheResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\TestCase;

class CacheResponseMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
    }

    public function test_response_is_cached_and_returned_on_subsequent_calls(): void
    {
        AssistantAgent::fake([
            'First response',
            'Second response',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->prompt('Hello');
        $second = $agent->prompt('Hello');

        $this->assertEquals('First response', $first->text);
        $this->assertEquals('First response', $second->text);
    }

    public function test_different_prompts_are_cached_separately(): void
    {
        AssistantAgent::fake([
            'Response A',
            'Response B',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->prompt('Prompt A');
        $second = $agent->prompt('Prompt B');

        $this->assertEquals('Response A', $first->text);
        $this->assertEquals('Response B', $second->text);
    }

    public function test_cached_response_has_fresh_invocation_id(): void
    {
        AssistantAgent::fake([
            'Cached response',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->prompt('Hello');
        $second = $agent->prompt('Hello');

        $this->assertNotEquals($first->invocationId, $second->invocationId);
    }

    public function test_cached_response_preserves_usage_and_meta(): void
    {
        AssistantAgent::fake([
            'Response',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->prompt('Hello');
        $second = $agent->prompt('Hello');

        $this->assertEquals($first->usage->toArray(), $second->usage->toArray());
        $this->assertEquals($first->meta->provider, $second->meta->provider);
        $this->assertEquals($first->meta->model, $second->meta->model);
    }

    public function test_streaming_responses_are_not_cached(): void
    {
        AssistantAgent::fake([
            'First stream',
            'Second stream',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->stream('Hello');
        $first->each(fn () => true);

        $second = $agent->stream('Hello');
        $second->each(fn () => true);

        $this->assertEquals('First stream', $first->text);
        $this->assertEquals('Second stream', $second->text);
    }

    public function test_structured_responses_are_cached(): void
    {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            ['symbol' => 'Ag'],
        ]);

        $agent = (new StructuredAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $first = $agent->prompt('What is gold?');
        $second = $agent->prompt('What is gold?');

        $this->assertInstanceOf(StructuredAgentResponse::class, $first);
        $this->assertInstanceOf(StructuredAgentResponse::class, $second);
        $this->assertEquals('Au', $first['symbol']);
        $this->assertEquals('Au', $second['symbol']);
    }

    public function test_then_callback_works_on_cached_response(): void
    {
        AssistantAgent::fake([
            'Response',
        ]);

        $agent = (new AssistantAgent)->withMiddleware([new CacheResponse(store: 'array')]);

        $callbackText = null;

        $agent->prompt('Hello')->then(function ($response) use (&$callbackText) {
            $callbackText = $response->text;
        });

        $this->assertEquals('Response', $callbackText);

        $cachedCallbackText = null;

        $agent->prompt('Hello')->then(function ($response) use (&$cachedCallbackText) {
            $cachedCallbackText = $response->text;
        });

        $this->assertEquals('Response', $cachedCallbackText);
    }
}
