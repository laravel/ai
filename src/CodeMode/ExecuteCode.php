<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The single model-facing tool code mode exposes: run a PHP program over the wrapped tools.
 */
class ExecuteCode implements Tool
{
    protected ?string $description = null;

    public function __construct(
        protected Catalog $catalog,
        protected int|float|null $timeout = null,
        protected ?int $maxToolCalls = null,
        protected ?int $maxOutputBytes = null,
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
        $functions = implode(', ', $this->availableFunctions());

        $catalog = $this->renderCatalog();

        return $this->description ??= <<<DESCRIPTION
        Execute a PHP program that orchestrates the tools listed below. Write plain PHP statements
        (the <?php tag is optional). The value you `return` becomes the result; `echo` output is
        captured and returned as logs. The result is JSON: {"ok": true, "value": ..., "logs": [...],
        "toolCalls": [...]} on success, or {"ok": false, "error": {"kind", "message"}, ...} on failure.

        Workflow:
        1. Call tools as tool('<path>', ['param' => value]). Every tool returns a string; when a tool
           returns JSON, parse it with json_decode(\$result) — json_decode always returns arrays.
        2. Sequence dependent calls, branch, and loop in one program instead of one call per turn.
        3. Filter and aggregate large results in code, returning only the data the conversation needs.
        4. Wrap calls in try/catch (catch (Exception \$e) { \$e->getMessage() }) to handle tool failures.

        The runtime is a restricted PHP subset: variables, arrays, string interpolation,
        arithmetic/comparison/logical operators, if/else, foreach/for/while, match/switch, try/catch,
        throw new Exception(...), closures, arrow functions, user-defined functions, and only these
        built-in functions: {$functions}.

        Not available: classes and objects (data is scalars and arrays), method calls, references,
        file/network/process access, and any function not listed above. Only the catalog tools exist.

        {$catalog}
        DESCRIPTION;
    }

    /**
     * Render the model-facing catalog, inlining signatures until the byte budget is spent.
     */
    protected function renderCatalog(): string
    {
        $inline = $this->catalog->inline();

        $catalog = $inline === [] ? '' : "Available tools:\n".implode("\n", $inline)."\n\n";

        if (! $this->catalog->isPartial()) {
            return rtrim($catalog);
        }

        return $catalog.'These tools also exist; look their signatures up with the search_tools tool, or with '
            ."search_tools('<terms>') inside a program — both return [['path' => ..., 'signature' => ...]] "
            ."ranked by relevance — then call the path exactly as given:\n"
            .implode(', ', $this->catalog->deferred());
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
            $this->onToolCallStart,
            $this->onToolCallEnd,
        );

        return $this->encode($interpreter->execute((string) $request->string('code')));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema
                ->string()
                ->description('The PHP program to execute.')
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
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $json = json_encode($result, $flags);

        if ($this->maxOutputBytes === null || strlen($json) <= $this->maxOutputBytes) {
            return $json;
        }

        $result['truncated'] = true;

        if (array_key_exists('value', $result)) {
            $envelope = strlen(json_encode([...$result, 'value' => '', 'logs' => []], $flags));

            $result['value'] = substr(
                json_encode($result['value'], $flags), 0, max(0, $this->maxOutputBytes - $envelope - 64)
            ).' ...[truncated]';
        }

        // Logs are kept from the start until the remaining budget is exhausted.
        while (strlen($json = json_encode($result, $flags)) > $this->maxOutputBytes && $result['logs'] !== []) {
            array_pop($result['logs']);
        }

        return strlen($json) <= $this->maxOutputBytes ? $json : substr($json, 0, $this->maxOutputBytes);
    }

    /**
     * The full set of callable built-ins, including tool() itself.
     *
     * @return array<int, string>
     */
    protected function availableFunctions(): array
    {
        $functions = [...Interpreter::FUNCTIONS, ...Interpreter::REF_FUNCTIONS];

        sort($functions);

        return $functions;
    }
}
