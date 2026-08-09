<?php

namespace Laravel\Ai\CodeMode;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Look up signatures for code mode tools left out of the inline catalog.
 *
 * Exposed alongside execute_code only when the catalog is partial, so discovery is a
 * plain tool call instead of something the model has to write a program to do.
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
        return 'Search the tools available to execute_code. Returns matching tool paths with their '
            .'call signatures, ranked by relevance; an empty query browses the catalog. Use the '
            .'returned path exactly as given in a tool(...) call inside an execute_code program.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return json_encode(
            $this->catalog->search((string) $request->string('query'), max(1, (int) ($request['limit'] ?? 10))),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The terms to search tool names and descriptions for.')->required(),
            'limit' => $schema->integer()->description('The maximum number of tools to return. Defaults to 10.'),
        ];
    }
}
