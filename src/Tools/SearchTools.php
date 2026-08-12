<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;

/**
 * Search the catalog of tools available through execute_tools.
 */
class SearchTools implements Tool
{
    public function __construct(protected ToolCatalog $catalog) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'search_tools';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search the tools available through execute_tools. Returns exact tool names, descriptions, and '
            .'complete argument JSON Schemas, ranked by relevance. An empty query browses the catalog.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return json_encode(
            $this->catalog->search((string) $request->string('query'), $request->integer('limit', 10)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The terms to search tool names and descriptions for. An empty query browses the catalog.'),
            'limit' => $schema->integer()->min(1)->max(50)->default(10)->description('The maximum number of tools to return.'),
        ];
    }
}
