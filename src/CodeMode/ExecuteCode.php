<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Run a bounded JSON orchestration program over wrapped tools.
 */
class ExecuteCode implements Tool
{
    /**
     * @param  Closure(Tool, string, array<string, mixed>, string): string|null  $toolInvoker
     */
    public function __construct(
        protected Catalog $catalog,
        protected int|float $timeout,
        protected int $maxToolCalls,
        protected int $maxOutputBytes,
        protected int $maxOperations,
        protected ?Closure $toolInvoker = null,
        protected ?Closure $onToolCallStart = null,
        protected ?Closure $onToolCallEnd = null,
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'execute_code';
    }

    /**
     * Get the description of the tool's purpose, including the program guide and tool catalog.
     */
    public function description(): string
    {
        $catalog = $this->renderCatalog();

        return <<<DESCRIPTION
        Execute a bounded JSON orchestration program over the tools listed below. Pass the program as
        a JSON string. The result is JSON: {"ok": true, "value": ..., "logs": [...],
        "toolCalls": [...]} on success, or {"ok": false, "error": {"kind", "message"}, ...} on failure.

        Program shape: {"steps": [...], "return": <expression>}.
        Steps:
        - {"set":"name","value":<expression>}
        - {"call":"tool.path","arguments":<object expression>,"save":"name"}
        - {"append":"listName","value":<expression>}
        - {"if":<boolean expression>,"then":[...],"else":[...]}
        - {"foreach":{"items":<array expression>,"as":"item","key":"optionalKey"},"do":[...]}
        - {"log":<expression>}

        Expressions are JSON values. A one-key object invokes one of these data-only operators:
        {"\$var":"name.optional.path"}, {"\$json":<string expression>}, {"\$concat":[...]},
        {"\$sum":<array>}, {"\$count":<array|string>}, {"\$not":<boolean>}, {"\$and":[...]},
        {"\$or":[...]}, {"\$eq":[a,b]}, {"\$neq":[a,b]}, {"\$gt":[a,b]}, {"\$gte":[a,b]},
        {"\$lt":[a,b]}, {"\$lte":[a,b]}, {"\$add":[a,b]}, {"\$subtract":[a,b]},
        {"\$multiply":[a,b]}, {"\$divide":[a,b]}, {"\$mod":[a,b]}, {"\$contains":[a,b]},
        {"\$in":[a,b]}, or {"\$coalesce":[...]}.

        There are no host-language callbacks, functions, classes, objects, file, network, or process
        primitives. Only an explicit call step can cause a side effect, through one catalog tool.

        {$catalog}
        DESCRIPTION;
    }

    /**
     * Render the model-facing catalog, inlining schemas until the byte budget is spent.
     */
    protected function renderCatalog(): string
    {
        $inline = $this->catalog->inline();

        $catalog = $inline === [] ? '' : "Available tools:\n".implode("\n", $inline)."\n\n";

        if (! $this->catalog->isPartial()) {
            return rtrim($catalog);
        }

        return $catalog.sprintf(
            '%d additional tools are available through search_tools. Search returns each exact path, description, and JSON Schema.',
            $this->catalog->deferredCount(),
        );
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $interpreter = new Interpreter(
            $this->catalog,
            $this->timeout,
            $this->maxToolCalls,
            $this->maxOperations,
            $this->toolInvoker,
            $this->onToolCallStart,
            $this->onToolCallEnd,
            $request->toolCallId(),
        );

        return $this->encode($interpreter->execute((string) $request->string('program')));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'program' => $schema
                ->string()
                ->description('The JSON orchestration program to execute.')
                ->required(),
        ];
    }

    /**
     * Encode a result for the model, truncating oversized values and logs to the byte budget.
     *
     * @param  array<string, mixed>  $result
     */
    protected function encode(array $result): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        $json = json_encode($result, $flags);

        if (strlen($json) <= $this->maxOutputBytes) {
            return $json;
        }

        $result['truncated'] = true;

        if (array_key_exists('value', $result)) {
            $result['value'] = '[truncated]';
        }

        while (strlen($json = json_encode($result, $flags)) > $this->maxOutputBytes && $result['logs'] !== []) {
            array_pop($result['logs']);
        }

        while (strlen($json = json_encode($result, $flags)) > $this->maxOutputBytes && $result['toolCalls'] !== []) {
            array_pop($result['toolCalls']);
        }

        return strlen($json) <= $this->maxOutputBytes
            ? $json
            : json_encode([
                'ok' => false,
                'error' => ['kind' => 'OutputLimitExceeded', 'message' => 'The program result exceeded the output byte limit.'],
                'logs' => [],
                'toolCalls' => [],
                'truncated' => true,
            ], $flags);
    }
}
