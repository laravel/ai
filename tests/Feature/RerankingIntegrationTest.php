<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Reranking as RerankingFacade;
use Laravel\Ai\Responses\RerankingResponse;
use Tests\TestCase;

class RerankingIntegrationTest extends TestCase
{
    public function test_documents_can_be_reranked(): void
    {
        Event::fake();

        $response = RerankingFacade::of([
            'Python is a high-level, general-purpose programming language.',
            'Laravel is a PHP web application framework with expressive, elegant syntax.',
            'React is a JavaScript library for building user interfaces.',
        ])->rerank('What is Laravel?');

        $this->assertInstanceOf(RerankingResponse::class, $response);
        $this->assertCount(3, $response);
        $this->assertEquals('cohere', $response->meta->provider);

        $this->assertStringContainsString('Laravel', $response->first()->document);

        Event::assertDispatched(Reranking::class);
        Event::assertDispatched(Reranked::class);
    }

    public function test_documents_can_be_reranked_with_limit(): void
    {
        $response = RerankingFacade::of([
            'Django is a Python web framework.',
            'Rails is a Ruby web framework.',
            'Laravel is a PHP web application framework.',
            'Express is a Node.js web framework.',
            'Spring is a Java web framework.',
        ])->limit(2)->rerank('PHP frameworks');

        $this->assertCount(2, $response);
        $this->assertGreaterThan($response->results[1]->score, $response->first()->score);
        $this->assertStringContainsString('Laravel', $response->first()->document);
    }
}
