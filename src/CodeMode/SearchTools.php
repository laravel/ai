<?php

namespace Laravel\Ai\CodeMode;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Look up schemas for code mode tools left out of the inline catalog.
 */
class SearchTools implements Tool
{
    public function __construct(protected Catalog $catalog) {}

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
        return 'Search the tools available to execute_code. Returns matching paths, descriptions, and JSON Schemas, '
            .'ranked by relevance; an empty query browses the catalog. Use the returned path exactly as given in a '
            .'call step inside an execute_code program.';
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
            'query' => $schema->string()->description('The terms to search tool names and descriptions for.')->required(),
            'limit' => $schema->integer()->min(1)->max(50)->default(10)->description('The maximum number of tools to return.'),
        ];
    }
}
