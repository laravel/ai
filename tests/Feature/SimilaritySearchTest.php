<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;

test('search results are returned', function () {
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

    expect(str_contains($results, json_encode($data, JSON_PRETTY_PRINT)))->toBeTrue();
});

test('using model creates similarity search', function () {
    $search = SimilaritySearch::usingModel(
        FakeVectorModel::class,
        'embedding',
        0.7
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->toContain('Relevant results found.');
    expect($results)->toContain('First document');
    expect($results)->toContain('Second document');
});

test('using model applies custom query closure', function () {
    $search = SimilaritySearch::usingModel(
        FakeVectorModel::class,
        'embedding',
        0.7,
        query: fn ($query) => $query->where('active', true)
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->toContain('Relevant results found.');
});

test('using model excludes embedding column from results', function () {
    $search = SimilaritySearch::usingModel(
        FakeVectorModel::class,
        'embedding',
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->not->toContain('embedding');
    expect($results)->toContain('First document');
});

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

    protected ?int $limit = null;

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
        return new Collection([
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
