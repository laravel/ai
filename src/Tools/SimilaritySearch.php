<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;

class SimilaritySearch implements Tool
{
    protected ?string $description;

    protected bool $rerank = false;

    protected Closure|array|string|null $rerankBy = null;

    protected ?int $rerankLimit = null;

    public function __construct(
        public Closure $using,
    ) {}

    /**
     * Create a new similarity search tool instance.
     */
    public static function usingModel(
        string $model,
        string $column,
        float $minSimilarity = 0.6,
        int $limit = 15,
        ?Closure $query = null): self
    {
        return new static(function (string $queryString) use ($model, $column, $minSimilarity, $limit, $query) {
            $pendingQuery = $model::query()->whereVectorSimilarTo($column, $queryString, $minSimilarity);

            if ($query) {
                $pendingQuery = $query($pendingQuery);
            }

            if ($limit) {
                $pendingQuery->limit($limit);
            }

            return $pendingQuery
                ->get()
                ->map(fn ($model) => collect($model->toArray())->except($column)->all());
        });
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return $this->description ?? 'Search for documents similar to a given query.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $results = call_user_func($this->using, $request->string('query'));

        $results = match (true) {
            is_array($results) => new Collection($results),
            $results instanceof Collection => $results,
            default => $results->get(),
        };

        if ($results->isEmpty()) {
            return 'No relevant results found.';
        }

        if ($this->rerank) {
            $results = $results->rerank(
                $this->rerankBy,
                $request->string('query'),
                limit: $this->rerankLimit
            );
        }

        return "Relevant results found. They are listed below sorted by relevance:\n\n".
            $results->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Set the tool's description.
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Indicate that the results should be reranked.
     */
    public function rerank(Closure|array|string $by, ?int $limit = null): self
    {
        $this->rerank = true;
        $this->rerankBy = $by;
        $this->rerankLimit = $limit;

        return $this;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The search query.')
                ->required(),
        ];
    }
}
