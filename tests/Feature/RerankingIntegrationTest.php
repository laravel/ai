<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
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

    public function test_collections_can_be_reranked_using_string_field(): void
    {
        $items = new Collection([
            ['id' => 1, 'content' => 'Django is a Python web framework.'],
            ['id' => 2, 'content' => 'Laravel is a PHP web application framework.'],
            ['id' => 3, 'content' => 'React is a JavaScript library.'],
        ]);

        $reranked = $items->rerank(by: 'content', query: 'PHP frameworks', limit: 2);

        $this->assertCount(2, $reranked);
        $this->assertEquals(2, $reranked->first()['id']);
    }

    public function test_collections_can_be_reranked_using_array_fields(): void
    {
        $items = new Collection([
            ['id' => 1, 'title' => 'Django Guide', 'body' => 'Learn Python web development.'],
            ['id' => 2, 'title' => 'Laravel Guide', 'body' => 'Learn PHP web development.'],
            ['id' => 3, 'title' => 'React Guide', 'body' => 'Learn JavaScript UI development.'],
        ]);

        $reranked = $items->rerank(by: ['title', 'body'], query: 'PHP frameworks', limit: 2);

        $this->assertCount(2, $reranked);
        $this->assertEquals(2, $reranked->first()['id']);
    }

    public function test_collections_can_be_reranked_using_closure(): void
    {
        $items = new Collection([
            ['id' => 1, 'title' => 'Django', 'body' => 'Python web framework.'],
            ['id' => 2, 'title' => 'Laravel', 'body' => 'PHP web framework.'],
            ['id' => 3, 'title' => 'React', 'body' => 'JavaScript library.'],
        ]);

        $reranked = $items->rerank(
            fn ($item) => $item['title'].': '.$item['body'],
            'PHP frameworks',
            limit: 2
        );

        $this->assertCount(2, $reranked);
        $this->assertEquals(2, $reranked->first()['id']);
    }
}
