<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;

class McpServerTool implements Tool
{
    protected const MCP_SERVER_TOOL = 'Laravel\\Mcp\\Server\\Tool';

    protected const MCP_REQUEST = 'Laravel\\Mcp\\Request';

    protected const MCP_RESPONSE = 'Laravel\\Mcp\\Response';

    protected const MCP_RESPONSE_FACTORY = 'Laravel\\Mcp\\ResponseFactory';

    public function __construct(protected object $tool) {}

    public static function supports(mixed $tool): bool
    {
        return is_object($tool) && is_a($tool, self::MCP_SERVER_TOOL);
    }

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): string
    {
        $container = Container::getInstance();

        $container->instance(self::MCP_REQUEST, new (self::MCP_REQUEST)($request->toArray()));

        try {
            $response = $container->call([$this->tool, 'handle']);
        } finally {
            $container->forgetInstance(self::MCP_REQUEST);
        }

        return $this->convertResponse($response);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

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

        return match (true) {
            ! $final->isError() => $text,
            $text === '' => 'MCP tool error.',
            default => 'MCP tool error: '.$text,
        };
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function json(array $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
