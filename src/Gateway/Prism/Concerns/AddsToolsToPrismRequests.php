<?php

namespace Laravel\Ai\Gateway\Prism\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\PrismTool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\McpServer;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\Request as ToolRequest;
use Prism\Prism\Tool as PrismToolDefinition;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\ValueObjects\ProviderTool as PrismProviderTool;
use Prism\Relay\RelayFactory;
use RuntimeException;

trait AddsToolsToPrismRequests
{
    /**
     * Add the given tools to the Prism request.
     */
    protected function addTools($request, array $tools, ?TextGenerationOptions $options = null)
    {
        $resolvedTools = $this->resolvePrismTools($tools);
        $defaultMaxSteps = round(max(count($tools), count($resolvedTools)) * 1.5);

        return $request
            ->withTools($resolvedTools)
            ->withToolChoice(ToolChoice::Auto)
            ->withMaxSteps(
                $options?->maxSteps ?? $defaultMaxSteps
            );
    }

    /**
     * Resolve the given tools into Prism tool definitions.
     *
     * @return array<int, PrismToolDefinition>
     */
    protected function resolvePrismTools(array $tools): array
    {
        return (new Collection($tools))->map(function ($tool) {
            return match (true) {
                $tool instanceof McpServer => $this->resolveRelayTools($tool),
                $tool instanceof ProviderTool => null,
                $tool instanceof PrismToolDefinition => [$tool],
                default => [$this->createPrismTool($tool)],
            };
        })->filter()->flatten(1)->values()->all();
    }

    /**
     * Resolve Prism tools from a configured MCP server.
     *
     * @return array<int, PrismToolDefinition>
     */
    protected function resolveRelayTools(McpServer $server): array
    {
        if (! class_exists(RelayFactory::class)) {
            throw new RuntimeException('MCP tools require the optional "prism-php/relay" package. Install it via: composer require prism-php/relay');
        }

        return (new RelayFactory)->tools($server->name, $server->config);
    }

    /**
     * Create a Prism tool from the given tool.
     */
    protected function createPrismTool(Tool $tool): PrismTool
    {
        $toolName = method_exists($tool, 'name')
            ? $tool->name()
            : class_basename($tool);

        return (new PrismTool)
            ->as($toolName)
            ->for((string) $tool->description())
            ->when(
                ! empty($tool->schema(new JsonSchemaTypeFactory)),
                fn ($prismTool) => $prismTool->withParameter(
                    new ObjectSchema($tool->schema(new JsonSchemaTypeFactory))
                )
            )
            ->using(fn ($arguments) => $this->invokeTool($tool, $arguments))
            ->withoutErrorHandling();
    }

    /**
     * Invoke the given tool with the given arguments.
     */
    protected function invokeTool(Tool $tool, array $arguments): string
    {
        $arguments = $arguments['schema_definition'] ?? $arguments;

        call_user_func($this->invokingToolCallback, $tool, $arguments);

        return (string) tap(
            $tool->handle(new ToolRequest($arguments)),
            fn ($result) => call_user_func($this->toolInvokedCallback, $tool, $arguments, $result)
        );
    }

    /**
     * Add the given provider tools to the Prism request.
     */
    protected function addProviderTools(Provider $provider, $request, array $tools)
    {
        return $request
            ->withProviderTools((new Collection($tools))->map(function ($tool) use ($provider) {
                return match (true) {
                    $tool instanceof FileSearch => $this->addFileSearchTool($provider, $tool),
                    $tool instanceof WebFetch => $this->addWebFetchTool($provider, $tool),
                    $tool instanceof WebSearch => $this->addWebSearchTool($provider, $tool),
                    default => null,
                };
            })->filter()->values()->all());
    }

    /**
     * Create the Prism provider tool for file search.
     */
    protected function addFileSearchTool(Provider $provider, FileSearch $tool): PrismProviderTool
    {
        $options = $provider instanceof SupportsFileSearch
            ? $provider->fileSearchToolOptions($tool)
            : throw new RuntimeException('Provider ['.$provider->name().'] does not support file search.');

        return match ($provider->driver()) {
            'openai' => new PrismProviderTool('file_search', options: $options),
            'gemini' => new PrismProviderTool('fileSearch', options: $options),
        };
    }

    /**
     * Create the Prism provider tool for web fetch.
     */
    protected function addWebFetchTool(Provider $provider, WebFetch $tool): PrismProviderTool
    {
        $options = $provider instanceof SupportsWebFetch
            ? $provider->webFetchToolOptions($tool)
            : throw new RuntimeException('Provider ['.$provider->name().'] does not support web fetch.');

        return match ($provider->driver()) {
            'anthropic' => new PrismProviderTool('web_fetch_20250910', 'web_fetch', options: $options),
            'gemini' => new PrismProviderTool('url_context'),
        };
    }

    /**
     * Create the Prism provider tool for web search.
     */
    protected function addWebSearchTool(Provider $provider, WebSearch $tool): PrismProviderTool
    {
        $options = $provider instanceof SupportsWebSearch
            ? $provider->webSearchToolOptions($tool)
            : throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');

        return match ($provider->driver()) {
            'anthropic' => new PrismProviderTool('web_search_20250305', 'web_search', options: $options),
            'gemini' => new PrismProviderTool('google_search'),
            'openai' => new PrismProviderTool('web_search', options: $options),
        };
    }
}
