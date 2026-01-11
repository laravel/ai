<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

class SimilaritySearchTest extends TestCase
{
    public function test_search_results_are_returned(): void
    {
        $data = [
            [
                'id' => 1,
                'query' => 'Test query',
            ],
            [
                'id' => 2,
                'query' => 'Test query',
            ],
        ];

        $search = new SimilaritySearch(using: function (string $query) use ($data) {
            return $data;
        });

        $results = $search->handle(new Request([
            'query' => 'Test query',
        ]));

        $this->assertTrue(str_contains($results, json_encode($data, JSON_PRETTY_PRINT)));
    }

    public function test_using_model_creates_similarity_search(): void
    {
        $search = SimilaritySearch::usingModel(
            FakeVectorModel::class,
            'embedding',
            0.7
        );

        $results = $search->handle(new Request([
            'query' => 'search term',
        ]));

        $this->assertStringContainsString('Relevant results found.', $results);
        $this->assertStringContainsString('First document', $results);
        $this->assertStringContainsString('Second document', $results);
    }

    public function test_using_model_applies_custom_query_closure(): void
    {
        $search = SimilaritySearch::usingModel(
            FakeVectorModel::class,
            'embedding',
            0.7,
            query: fn ($query) => $query->where('active', true)
        );

        $results = $search->handle(new Request([
            'query' => 'search term',
        ]));

        $this->assertStringContainsString('Relevant results found.', $results);
    }

    public function test_using_model_excludes_embedding_column_from_results(): void
    {
        $search = SimilaritySearch::usingModel(
            FakeVectorModel::class,
            'embedding',
        );

        $results = $search->handle(new Request([
            'query' => 'search term',
        ]));

        $this->assertStringNotContainsString('embedding', $results);
        $this->assertStringContainsString('First document', $results);
    }
}

class FakeVectorModel
{
    public static function query(): FakeQueryBuilder
    {
        return new FakeQueryBuilder;
    }
}

class FakeQueryBuilder
{
    protected array $conditions = [];

    protected ?int $limit;

    public function whereVectorSimilarTo(string $column, string $query): self
    {
        $this->conditions['vector'] = [$column, $query];

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->conditions[$column] = $value;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function get(): Collection
    {
        return collect([
            new FakeModel(['id' => 1, 'content' => 'First document', 'embedding' => [0.1, 0.2, 0.3]]),
            new FakeModel(['id' => 2, 'content' => 'Second document', 'embedding' => [0.4, 0.5, 0.6]]),
        ]);
    }
}

class FakeModel
{
    public function __construct(protected array $attributes) {}

    public function toArray(): array
    {
        return $this->attributes;
    }
}
