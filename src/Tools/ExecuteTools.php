<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Throwable;

/**
 * Execute catalog tools discovered through search_tools.
 */
class ExecuteTools implements Tool
{
    /**
     * @param  Closure(Tool, string, array<string, mixed>, string): string|null  $toolInvoker
     */
    public function __construct(
        protected ToolCatalog $catalog,
        protected int $maxToolCalls,
        protected int $maxOutputBytes,
        protected ?Closure $toolInvoker = null,
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'execute_tools';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Execute multiple catalog tools synchronously in order, stopping on the first error so later '
            .'calls never run against a failed state. Use the exact names and arguments returned by '
            .'search_tools. When a call depends on a previous result, call execute_tools again.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $calls = $request['calls'] ?? null;

        if (! is_array($calls) || ! array_is_list($calls) || $calls === []) {
            return $this->failure('InvalidCalls', 'The [calls] argument must be a non-empty array of tool calls.');
        }

        if (count($calls) > $this->maxToolCalls) {
            return $this->failure('ToolCallLimitExceeded', sprintf(
                'A single execute_tools call may make at most %d tool calls.', $this->maxToolCalls
            ));
        }

        $results = [];

        foreach ($calls as $index => $call) {
            $results[] = $result = $this->executeCall(is_array($call) ? $call : [], $request->toolCallId(), $index);
            $output = ['ok' => ! isset($result['error']), 'results' => $results];
            $encoded = $this->encode($output);

            if (strlen($encoded) > $this->maxOutputBytes) {
                return $this->failure('OutputLimitExceeded', sprintf(
                    'The tool output exceeded %d bytes.', $this->maxOutputBytes
                ), count($results), $index + 1);
            }

            if (isset($result['error'])) {
                return $encoded;
            }
        }

        return $this->encode(['ok' => true, 'results' => $results]);
    }

    /**
     * Execute a single catalog tool call, capturing failures as error results.
     *
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    protected function executeCall(array $call, ?string $parentToolCallId, int $index): array
    {
        $name = $call['name'] ?? null;
        $arguments = $call['arguments'] ?? [];

        if (! is_string($name) || $name === '') {
            return ['name' => null, 'error' => 'Each call requires a [name] string returned by search_tools.'];
        }

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return ['name' => $name, 'error' => 'The [arguments] value must be an object matching the tool schema.'];
        }

        $tool = $this->catalog->tool($name);

        if ($tool === null) {
            return ['name' => $name, 'error' => sprintf('Unknown tool [%s]. Use search_tools to discover available tools.', $name)];
        }

        $toolCallId = ($parentToolCallId ?? 'tool-search').':'.$index;

        try {
            $result = $this->toolInvoker !== null
                ? ($this->toolInvoker)($tool, $name, $arguments, $toolCallId)
                : (string) $tool->handle(new Request($arguments, $toolCallId));
        } catch (Throwable $exception) {
            return ['name' => $name, 'error' => sprintf('Tool [%s] failed: %s', $name, $exception->getMessage())];
        }

        return ['name' => $name, 'result' => $result];
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'calls' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('The exact tool name returned by search_tools.')->required(),
                    'arguments' => $schema->object()->description('The arguments matching the tool schema.'),
                ]))
                ->min(1)
                ->max($this->maxToolCalls)
                ->description('Tool calls to execute synchronously in order, stopping on the first error.')
                ->required(),
        ];
    }

    /**
     * Encode a failure envelope for the model.
     */
    protected function failure(string $kind, string $message, ?int $completed = null, ?int $attempted = null): string
    {
        return $this->encode(array_filter([
            'ok' => false,
            'error' => ['kind' => $kind, 'message' => $message],
            'completedToolCalls' => $completed,
            'attemptedToolCalls' => $attempted,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
