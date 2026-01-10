<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;

class SimilaritySearch implements Tool
{
    public function __construct(
        public Closure $using,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search for documents similar to a given query.';
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
            return 'No results found.';
        }

        return "Results found. They are listed below sorted by relevance:\n\n".
            $results->toJson(JSON_PRETTY_PRINT);
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
