<?php

namespace Laravel\Ai\Gateway\Prism\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\Request as ToolRequest;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool as PrismProviderTool;
use RuntimeException;

trait AddsToolsToPrismRequests
{
    /**
     * Add the given tools to the Prism request.
     */
    protected function addTools($request, array $tools, ?TextGenerationOptions $options = null)
    {
        return $request
            ->withTools(collect($tools)->map(function ($tool) {
                if ($tool instanceof ProviderTool) {
                    return;
                }

                return (new PrismTool)
                    ->as(class_basename($tool))
                    ->for((string) $tool->description())
                    ->when(
                        ! empty($tool->schema(new JsonSchemaTypeFactory)),
                        fn ($prismTool) => $prismTool->withParameter(
                            new ObjectSchema($tool->schema(new JsonSchemaTypeFactory))
                        )
                    )
                    ->using(function ($arguments) use ($tool) {
                        $arguments = $arguments['schema_definition'] ?? [];

                        call_user_func($this->invokingToolCallback, $tool, $arguments);

                        return (string) tap(
                            $tool->handle(new ToolRequest($arguments)),
                            function ($result) use ($tool, $arguments) {
                                call_user_func($this->toolInvokedCallback, $tool, $arguments, $result);
                            }
                        );
                    })
                    ->withoutErrorHandling();
            })->filter()->values()->all())
            ->withToolChoice(ToolChoice::Auto)
            ->withMaxSteps($options?->maxSteps ?? round(count($tools) * 1.5));
    }

    /**
     * Add the given provider tools to the Prism request.
     */
    protected function addProviderTools(Provider $provider, $request, array $tools, ?TextGenerationOptions $options = null)
    {
        return $request
            ->withProviderTools(collect($tools)->map(function ($tool) use ($provider) {
                return match (true) {
                    $tool instanceof WebFetch => $this->addWebFetchTool($provider, $tool),
                    $tool instanceof WebSearch => $this->addWebSearchTool($provider, $tool),
                    default => null,
                };
            })->filter()->values()->all());
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
