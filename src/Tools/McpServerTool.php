<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Concerns\NormalizesMcpResult;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Content\ResourceLink;

class McpServerTool implements Tool
{
    use NormalizesMcpResult;

    /**
     * The MCP server tool class name.
     */
    protected const MCP_SERVER_TOOL = \Laravel\Mcp\Server\Tool::class;

    /**
     * The MCP request class name.
     */
    protected const MCP_REQUEST = \Laravel\Mcp\Request::class;

    /**
     * The MCP response class name.
     */
    protected const MCP_RESPONSE = Response::class;

    /**
     * The MCP response factory class name.
     */
    protected const MCP_RESPONSE_FACTORY = ResponseFactory::class;

    /**
     * Create a new MCP server tool wrapper instance.
     */
    public function __construct(protected object $tool) {}

    /**
     * Determine whether the given value is an MCP server tool.
     */
    public static function supports(mixed $tool): bool
    {
        return $tool instanceof \Laravel\Mcp\Server\Tool;
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->tool->name();
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return $this->tool->description();
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $container = Container::getInstance();

        $previous = $container->bound(self::MCP_REQUEST)
            ? $container->make(self::MCP_REQUEST)
            : null;

        $container->instance(self::MCP_REQUEST, new (self::MCP_REQUEST)($request->toArray()));

        try {
            return $this->convertResponse($container->call([$this->tool, 'handle']));
        } finally {
            $previous !== null
                ? $container->instance(self::MCP_REQUEST, $previous)
                : $container->forgetInstance(self::MCP_REQUEST);
        }
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    /**
     * Convert an MCP server response into tool output.
     */
    protected function convertResponse(mixed $response): string
    {
        if (is_a($response, self::MCP_RESPONSE_FACTORY)) {
            $structured = $response->getStructuredContent();

            if (is_array($structured) && $structured !== []) {
                return $this->json($structured);
            }

            return $this->finalResponse($response->responses()->all());
        }

        $items = $response instanceof Generator
            ? iterator_to_array($response, false)
            : [$response];

        return $this->finalResponse($this->normalize($items));
    }

    /**
     * Flatten Response, ResponseFactory, string, and nested array items into a flat list of Response instances.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, object>
     */
    protected function normalize(array $items): array
    {
        return collect($items)->flatMap(fn (mixed $item): array => match (true) {
            is_a($item, self::MCP_RESPONSE) => [$item],
            is_a($item, self::MCP_RESPONSE_FACTORY) => $item->responses()->all(),
            is_string($item) => [(self::MCP_RESPONSE)::text($item)],
            is_array($item) => $this->normalize($item),
            default => [],
        })->all();
    }

    /**
     * Reduce a list of responses to tool output, preserving MCP App resource links.
     *
     * When an MCP tool returns a `ResourceLink` with a `ui://` URI alongside
     * text, the URI is included as `appResourceUri` in a JSON payload so the
     * host can fetch the HTML via `resources/read` and render it in an iframe.
     * Without a `ResourceLink`, returns plain text as before.
     *
     * @param  array<int, object>  $responses
     */
    protected function finalResponse(array $responses): string
    {
        $text = '';
        $appUri = null;
        $isError = false;

        foreach (array_reverse($responses) as $response) {
            if ($response->isNotification()) {
                continue;
            }

            $content = $response->content();

            if ($content instanceof ResourceLink && str_starts_with((string) $content, 'ui://')) {
                $appUri ??= (string) $content;
                $isError = $isError || $response->isError();

                continue;
            }

            if ($text === '') {
                $text = (string) $content;
                $isError = $response->isError();
            }

            if ($text !== '' && $appUri !== null) {
                break;
            }
        }

        if ($text === '' && $appUri === null) {
            return '';
        }

        if ($isError) {
            $text = $this->errorMessage($text);
        }

        if ($appUri !== null) {
            return json_encode([
                'text' => $text,
                'appResourceUri' => $appUri,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $text;
    }
}
