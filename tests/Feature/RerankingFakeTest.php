<?php

namespace Tests\Feature;

use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;
use RuntimeException;
use Tests\TestCase;

class RerankingFakeTest extends TestCase
{
    public function test_can_fake_reranking(): void
    {
        Reranking::fake();

        $response = Reranking::of([
            'Laravel is a PHP framework',
            'Python is a programming language',
            'React is a JavaScript library',
        ])->rerank('What is Laravel?');

        $this->assertCount(3, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
    }

    public function test_can_fake_reranking_with_limit(): void
    {
        Reranking::fake();

        $response = Reranking::of([
            'Laravel is a PHP framework',
            'Python is a programming language',
            'React is a JavaScript library',
            'Vue is a JavaScript framework',
            'Ruby is a programming language',
        ])->limit(3)->rerank('What is Laravel?');

        $this->assertCount(3, $response);
    }

    public function test_can_fake_reranking_with_custom_response(): void
    {
        Reranking::fake([
            [
                new RankedDocument(index: 0, document: 'First doc', score: 0.95),
                new RankedDocument(index: 1, document: 'Second doc', score: 0.75),
            ],
        ]);

        $response = Reranking::of(['First doc', 'Second doc'])->rerank('query');

        $this->assertCount(2, $response);
        $this->assertEquals(0.95, $response->first()->score);
        $this->assertEquals('First doc', $response->first()->document);
    }

    public function test_can_fake_reranking_with_closure(): void
    {
        Reranking::fake(function (RerankingPrompt $prompt) {
            return collect($prompt->documents)->map(fn ($doc, $index) => new RankedDocument(
                index: $index,
                document: $doc,
                score: 1.0 - ($index * 0.1),
            ))->all();
        });

        $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])->rerank('test query');

        $this->assertCount(3, $response);
        $this->assertEquals(1.0, $response->first()->score);
        $this->assertEquals('Doc A', $response->first()->document);
    }

    public function test_can_assert_reranked(): void
    {
        Reranking::fake();

        Reranking::of(['Laravel is great', 'PHP is cool'])->rerank('Laravel');

        Reranking::assertReranked(function (RerankingPrompt $prompt) {
            return $prompt->contains('Laravel');
        });

        Reranking::assertReranked(function (RerankingPrompt $prompt) {
            return $prompt->documentsContain('Laravel is great');
        });
    }

    public function test_can_assert_not_reranked(): void
    {
        Reranking::fake();

        Reranking::of(['Laravel is great'])->rerank('Laravel');

        Reranking::assertNotReranked(function (RerankingPrompt $prompt) {
            return $prompt->contains('Python');
        });

        Reranking::assertNotReranked(function (RerankingPrompt $prompt) {
            return $prompt->documentsContain('Python is great');
        });
    }

    public function test_can_assert_nothing_reranked(): void
    {
        Reranking::fake();

        Reranking::assertNothingReranked();
    }

    public function test_can_prevent_stray_rerankings(): void
    {
        $this->expectException(RuntimeException::class);

        Reranking::fake()->preventStrayRerankings();

        Reranking::of(['Doc 1', 'Doc 2'])->rerank('query');
    }

    public function test_fake_reranking_shuffles_documents(): void
    {
        Reranking::fake();

        $documents = ['Doc A', 'Doc B', 'Doc C', 'Doc D', 'Doc E'];

        $response = Reranking::of($documents)->rerank('query');

        $this->assertCount(5, $response);

        foreach ($response as $result) {
            $this->assertContains($result->document, $documents);
            $this->assertEquals($documents[$result->index], $result->document);
        }
    }

    public function test_can_iterate_over_response(): void
    {
        Reranking::fake();

        $response = Reranking::of(['Doc A', 'Doc B'])->rerank('query');

        $documents = [];

        foreach ($response as $result) {
            $documents[] = $result->document;
        }

        $this->assertCount(2, $documents);
    }

    public function test_can_get_documents_in_reranked_order(): void
    {
        Reranking::fake([
            [
                new RankedDocument(index: 1, document: 'Second', score: 0.9),
                new RankedDocument(index: 0, document: 'First', score: 0.5),
            ],
        ]);

        $response = Reranking::of(['First', 'Second'])->rerank('query');

        $this->assertEquals(['Second', 'First'], $response->documents());
    }

    public function test_prompt_records_limit(): void
    {
        Reranking::fake();

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])->limit(2)->rerank('query');

        Reranking::assertReranked(function (RerankingPrompt $prompt) {
            return $prompt->limit === 2 && $prompt->count() === 3;
        });
    }
}
