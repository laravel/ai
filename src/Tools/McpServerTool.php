<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Concerns\NormalizesMcpResult;

class McpServerTool implements Tool
{
    use NormalizesMcpResult;

    /**
     * The MCP server tool class name.
     */
    protected const MCP_SERVER_TOOL = 'Laravel\\Mcp\\Server\\Tool';

    /**
     * The MCP request class name.
     */
    protected const MCP_REQUEST = 'Laravel\\Mcp\\Request';

    /**
     * The MCP response class name.
     */
    protected const MCP_RESPONSE = 'Laravel\\Mcp\\Response';

    /**
     * The MCP response factory class name.
     */
    protected const MCP_RESPONSE_FACTORY = 'Laravel\\Mcp\\ResponseFactory';

    /**
     * Create a new MCP server tool wrapper instance.
     */
    public function __construct(protected object $tool) {}

    /**
     * Determine whether the given value is an MCP server tool.
     */
    public static function supports(mixed $tool): bool
    {
        return is_object($tool) && is_a($tool, self::MCP_SERVER_TOOL);
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
            $response = $container->call([$this->tool, 'handle']);
        } finally {
            $previous !== null
                ? $container->instance(self::MCP_REQUEST, $previous)
                : $container->forgetInstance(self::MCP_REQUEST);
        }

        return $this->convertResponse($response);
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
     * Reduce a list of responses to the last non-notification response's text.
     *
     * @param  array<int, object>  $responses
     */
    protected function finalResponse(array $responses): string
    {
        $final = collect($responses)->last(fn (object $response): bool => ! $response->isNotification());

        if ($final === null) {
            return '';
        }

        $text = (string) $final->content();

        return $final->isError()
            ? $this->errorMessage($text)
            : $text;
    }
}
