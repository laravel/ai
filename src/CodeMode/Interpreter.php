<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use JsonException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Execute the bounded, data-only orchestration language used by code mode.
 */
class Interpreter
{
    protected const MAX_PROGRAM_BYTES = 65536;

    protected const MAX_DEPTH = 32;

    protected const MAX_COLLECTION_ITEMS = 1000;

    /** @var array<string, mixed> */
    protected array $variables = [];

    /** @var array<int, string> */
    protected array $logs = [];

    /** @var array<int, string> */
    protected array $toolCalls = [];

    protected int $operations = 0;

    protected ?float $deadline = null;

    /**
     * @param  Closure(Tool, string, array<string, mixed>, string): string|null  $toolInvoker
     * @param  Closure(array<string, mixed>): void|null  $onToolCallStart
     * @param  Closure(array<string, mixed>): void|null  $onToolCallEnd
     */
    public function __construct(
        protected Catalog $catalog,
        protected int|float $timeout,
        protected int $maxToolCalls,
        protected int $maxOperations,
        protected ?Closure $toolInvoker = null,
        protected ?Closure $onToolCallStart = null,
        protected ?Closure $onToolCallEnd = null,
        protected ?string $parentToolCallId = null,
    ) {}

    /**
     * Execute a JSON orchestration program and return a model-facing result.
     *
     * @return array<string, mixed>
     */
    public function execute(string $program): array
    {
        $this->variables = [];
        $this->logs = [];
        $this->toolCalls = [];
        $this->operations = 0;
        $this->deadline = hrtime(true) / 1e9 + $this->timeout;

        try {
            if (strlen($program) > self::MAX_PROGRAM_BYTES) {
                throw new Diagnostic('ProgramTooLarge', sprintf(
                    'The program may contain at most %d bytes.', self::MAX_PROGRAM_BYTES
                ));
            }

            $decoded = json_decode($program, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);

            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new Diagnostic('InvalidProgram', 'The program must be a JSON object.');
            }

            $steps = $decoded['steps'] ?? [];

            if (! is_array($steps) || ! array_is_list($steps)) {
                throw new Diagnostic('InvalidProgram', 'The program [steps] must be an array.');
            }

            $this->executeSteps($steps);

            $value = array_key_exists('return', $decoded)
                ? $this->evaluate($decoded['return'])
                : null;

            return [
                'ok' => true,
                'value' => $this->dataValue($value),
                'logs' => $this->logs,
                'toolCalls' => $this->toolCalls,
            ];
        } catch (JsonException $exception) {
            return $this->failure('InvalidProgram', 'The program is not valid JSON: '.$exception->getMessage());
        } catch (Diagnostic $diagnostic) {
            return $this->failure($diagnostic->kind, $diagnostic->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function failure(string $kind, string $message): array
    {
        return [
            'ok' => false,
            'error' => ['kind' => $kind, 'message' => $message],
            'logs' => $this->logs,
            'toolCalls' => $this->toolCalls,
        ];
    }

    /**
     * @param  array<int, mixed>  $steps
     */
    protected function executeSteps(array $steps): void
    {
        if (count($steps) > self::MAX_COLLECTION_ITEMS) {
            throw new Diagnostic('CollectionLimitExceeded', 'A step list may contain at most '.self::MAX_COLLECTION_ITEMS.' items.');
        }

        foreach ($steps as $step) {
            $this->tick();

            if (! is_array($step) || array_is_list($step)) {
                throw new Diagnostic('InvalidProgram', 'Every step must be a JSON object.');
            }

            $this->executeStep($step);
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function executeStep(array $step): void
    {
        if (array_key_exists('set', $step)) {
            $name = $this->variableName($step['set']);
            $this->variables[$name] = $this->evaluate($step['value'] ?? null);

            return;
        }

        if (array_key_exists('call', $step)) {
            $this->executeCall($step);

            return;
        }

        if (array_key_exists('append', $step)) {
            $name = $this->variableName($step['append']);
            $values = $this->variables[$name] ?? [];

            if (! is_array($values) || ! array_is_list($values)) {
                throw new Diagnostic('TypeError', sprintf('The variable [%s] must contain a list before appending.', $name));
            }

            if (count($values) >= self::MAX_COLLECTION_ITEMS) {
                throw new Diagnostic('CollectionLimitExceeded', 'A list may contain at most '.self::MAX_COLLECTION_ITEMS.' items.');
            }

            $values[] = $this->evaluate($step['value'] ?? null);
            $this->variables[$name] = $values;

            return;
        }

        if (array_key_exists('if', $step)) {
            $condition = $this->evaluate($step['if']);

            if (! is_bool($condition)) {
                throw new Diagnostic('TypeError', 'An [if] expression must evaluate to a boolean.');
            }

            $branch = $condition ? ($step['then'] ?? []) : ($step['else'] ?? []);

            if (! is_array($branch) || ! array_is_list($branch)) {
                throw new Diagnostic('InvalidProgram', 'The [then] and [else] branches must be arrays of steps.');
            }

            $this->executeSteps($branch);

            return;
        }

        if (array_key_exists('foreach', $step)) {
            $this->executeForeach($step);

            return;
        }

        if (array_key_exists('log', $step)) {
            if (count($this->logs) >= self::MAX_COLLECTION_ITEMS) {
                throw new Diagnostic('CollectionLimitExceeded', 'A program may emit at most '.self::MAX_COLLECTION_ITEMS.' log entries.');
            }

            $this->logs[] = $this->stringValue($this->evaluate($step['log']));

            return;
        }

        throw new Diagnostic('InvalidProgram', 'Unknown step. Use set, call, append, if, foreach, or log.');
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function executeCall(array $step): void
    {
        if (! is_string($step['call']) || $step['call'] === '') {
            throw new Diagnostic('InvalidProgram', 'A [call] step requires a literal tool path.');
        }

        $path = $step['call'];
        $tool = $this->catalog->tool($path);

        if ($tool === null) {
            throw new Diagnostic('UnknownTool', sprintf(
                'Unknown tool [%s]. Use search_tools to discover available paths.', $path
            ));
        }

        if (count($this->toolCalls) >= $this->maxToolCalls) {
            throw new Diagnostic('ToolCallLimitExceeded', sprintf(
                'The program exceeded the limit of %d tool calls.', $this->maxToolCalls
            ));
        }

        $arguments = $this->evaluate($step['arguments'] ?? []);

        if (! is_array($arguments) || array_is_list($arguments) && $arguments !== []) {
            throw new Diagnostic('InvalidToolInput', sprintf('Tool [%s] arguments must evaluate to an object.', $path));
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->dataValue($arguments);
        $index = count($this->toolCalls);
        $toolCallId = ($this->parentToolCallId ?? 'code-mode').':'.$index;
        $this->toolCalls[] = $path;

        if ($this->onToolCallStart !== null) {
            ($this->onToolCallStart)([
                'id' => $toolCallId,
                'index' => $index,
                'name' => $path,
                'input' => $arguments,
            ]);
        }

        $started = hrtime(true);

        try {
            $result = $this->toolInvoker !== null
                ? ($this->toolInvoker)($tool, $path, $arguments, $toolCallId)
                : (string) $tool->handle(new Request($arguments, $toolCallId));
        } catch (Throwable $exception) {
            $this->notifyToolCallEnd($toolCallId, $index, $path, $arguments, $started, 'failure', $exception->getMessage());

            throw new Diagnostic('ToolExecutionFailed', sprintf('Tool [%s] failed: %s', $path, $exception->getMessage()));
        }

        $this->tick();
        $this->notifyToolCallEnd($toolCallId, $index, $path, $arguments, $started, 'success');

        if (array_key_exists('save', $step)) {
            $this->variables[$this->variableName($step['save'])] = $result;
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function executeForeach(array $step): void
    {
        $definition = $step['foreach'];

        if (! is_array($definition) || array_is_list($definition)) {
            throw new Diagnostic('InvalidProgram', 'A [foreach] step requires an object with [items] and [as].');
        }

        $items = $this->evaluate($definition['items'] ?? null);

        if (! is_array($items)) {
            throw new Diagnostic('TypeError', 'The [foreach.items] expression must evaluate to an array.');
        }

        if (count($items) > self::MAX_COLLECTION_ITEMS) {
            throw new Diagnostic('CollectionLimitExceeded', 'A foreach loop may visit at most '.self::MAX_COLLECTION_ITEMS.' items.');
        }

        $valueName = $this->variableName($definition['as'] ?? null);
        $keyName = array_key_exists('key', $definition) ? $this->variableName($definition['key']) : null;
        $body = $step['do'] ?? [];

        if (! is_array($body) || ! array_is_list($body)) {
            throw new Diagnostic('InvalidProgram', 'A [foreach.do] value must be an array of steps.');
        }

        foreach ($items as $key => $value) {
            $this->tick();
            $this->variables[$valueName] = $value;

            if ($keyName !== null) {
                $this->variables[$keyName] = $key;
            }

            $this->executeSteps($body);
        }
    }

    protected function evaluate(mixed $expression, int $depth = 0): mixed
    {
        $this->tick();

        if ($depth > self::MAX_DEPTH) {
            throw new Diagnostic('ValueDepthExceeded', 'Values may nest at most '.self::MAX_DEPTH.' levels deep.');
        }

        if (! is_array($expression)) {
            return $expression;
        }

        if (count($expression) > self::MAX_COLLECTION_ITEMS) {
            throw new Diagnostic('CollectionLimitExceeded', 'A value may contain at most '.self::MAX_COLLECTION_ITEMS.' items.');
        }

        if (count($expression) === 1 && is_string($operator = array_key_first($expression)) && str_starts_with($operator, '$')) {
            return $this->evaluateOperator($operator, $expression[$operator], $depth + 1);
        }

        $result = [];

        foreach ($expression as $key => $value) {
            $result[$key] = $this->evaluate($value, $depth + 1);
        }

        return $result;
    }

    protected function evaluateOperator(string $operator, mixed $operand, int $depth): mixed
    {
        return match ($operator) {
            '$var' => $this->variable($operand),
            '$json' => $this->decodeJson($this->evaluate($operand, $depth)),
            '$concat' => implode('', array_map($this->stringValue(...), $this->operandList($operand, $depth))),
            '$sum' => $this->sum($this->evaluate($operand, $depth)),
            '$count' => $this->countValue($this->evaluate($operand, $depth)),
            '$not' => ! $this->booleanValue($this->evaluate($operand, $depth)),
            '$and' => $this->and($this->operandList($operand, $depth)),
            '$or' => $this->or($this->operandList($operand, $depth)),
            '$eq' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left === $right),
            '$neq' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left !== $right),
            '$gt' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left > $right),
            '$gte' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left >= $right),
            '$lt' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left < $right),
            '$lte' => $this->binary($operand, $depth, fn (mixed $left, mixed $right): bool => $left <= $right),
            '$add' => $this->numericBinary($operand, $depth, fn (int|float $left, int|float $right): int|float => $left + $right),
            '$subtract' => $this->numericBinary($operand, $depth, fn (int|float $left, int|float $right): int|float => $left - $right),
            '$multiply' => $this->numericBinary($operand, $depth, fn (int|float $left, int|float $right): int|float => $left * $right),
            '$divide' => $this->numericBinary($operand, $depth, function (int|float $left, int|float $right): int|float {
                if ($right == 0) {
                    throw new Diagnostic('DivisionByZero', 'Cannot divide by zero.');
                }

                return $left / $right;
            }),
            '$mod' => $this->numericBinary($operand, $depth, function (int|float $left, int|float $right): int {
                if ($right == 0) {
                    throw new Diagnostic('DivisionByZero', 'Cannot divide by zero.');
                }

                return (int) $left % (int) $right;
            }),
            '$contains' => $this->binary($operand, $depth, fn (mixed $haystack, mixed $needle): bool => is_string($haystack)
                ? is_string($needle) && str_contains($haystack, $needle)
                : (is_array($haystack) && in_array($needle, $haystack, true))),
            '$in' => $this->binary($operand, $depth, fn (mixed $needle, mixed $haystack): bool => is_array($haystack) && in_array($needle, $haystack, true)),
            '$coalesce' => $this->coalesce($operand, $depth),
            default => throw new Diagnostic('InvalidProgram', sprintf('Unknown expression operator [%s].', $operator)),
        };
    }

    protected function variable(mixed $path): mixed
    {
        if (! is_string($path) || $path === '') {
            throw new Diagnostic('InvalidProgram', 'The [$var] operator requires a variable path string.');
        }

        $segments = explode('.', $path);
        $name = array_shift($segments);

        if (! array_key_exists($name, $this->variables)) {
            throw new Diagnostic('UndefinedVariable', sprintf('Variable [%s] is not defined.', $name));
        }

        $value = $this->variables[$name];

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new Diagnostic('UndefinedValue', sprintf('Value path [%s] does not exist.', $path));
            }

            $value = $value[$segment];
        }

        return $value;
    }

    protected function decodeJson(mixed $value): mixed
    {
        if (! is_string($value)) {
            throw new Diagnostic('TypeError', 'The [$json] operator requires a string.');
        }

        try {
            return json_decode($value, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Diagnostic('InvalidJson', $exception->getMessage());
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function operandList(mixed $operand, int $depth): array
    {
        if (! is_array($operand) || ! array_is_list($operand)) {
            throw new Diagnostic('InvalidProgram', 'This expression operator requires an array operand.');
        }

        return array_map(fn (mixed $item): mixed => $this->evaluate($item, $depth), $operand);
    }

    protected function binary(mixed $operand, int $depth, Closure $operation): mixed
    {
        $values = $this->operandList($operand, $depth);

        if (count($values) !== 2) {
            throw new Diagnostic('InvalidProgram', 'A binary expression requires exactly two operands.');
        }

        return $operation($values[0], $values[1]);
    }

    protected function numericBinary(mixed $operand, int $depth, Closure $operation): int|float
    {
        return $this->binary($operand, $depth, function (mixed $left, mixed $right) use ($operation): int|float {
            if (! is_int($left) && ! is_float($left) || ! is_int($right) && ! is_float($right)) {
                throw new Diagnostic('TypeError', 'Arithmetic operands must be numbers.');
            }

            return $operation($left, $right);
        });
    }

    protected function sum(mixed $value): int|float
    {
        if (! is_array($value)) {
            throw new Diagnostic('TypeError', 'The [$sum] operator requires an array.');
        }

        $sum = 0;

        foreach ($value as $item) {
            $this->tick();

            if (! is_int($item) && ! is_float($item)) {
                throw new Diagnostic('TypeError', 'The [$sum] operator accepts only numbers.');
            }

            $sum += $item;
        }

        return $sum;
    }

    protected function countValue(mixed $value): int
    {
        if (! is_array($value) && ! is_string($value)) {
            throw new Diagnostic('TypeError', 'The [$count] operator requires an array or string.');
        }

        return is_array($value) ? count($value) : strlen($value);
    }

    /** @param array<int, mixed> $values */
    protected function and(array $values): bool
    {
        foreach ($values as $value) {
            if (! $this->booleanValue($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $values */
    protected function or(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->booleanValue($value)) {
                return true;
            }
        }

        return false;
    }

    protected function coalesce(mixed $operand, int $depth): mixed
    {
        if (! is_array($operand) || ! array_is_list($operand)) {
            throw new Diagnostic('InvalidProgram', 'The [$coalesce] operator requires an array operand.');
        }

        foreach ($operand as $item) {
            $value = $this->evaluate($item, $depth);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function booleanValue(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new Diagnostic('TypeError', 'Logical operands must be booleans.');
        }

        return $value;
    }

    protected function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new Diagnostic('TypeError', 'This value cannot be converted to a string.');
    }

    protected function variableName(mixed $name): string
    {
        if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new Diagnostic('InvalidProgram', 'Variable names must start with a letter or underscore and contain only letters, numbers, and underscores.');
        }

        return $name;
    }

    protected function dataValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new Diagnostic('ValueDepthExceeded', 'Values may nest at most '.self::MAX_DEPTH.' levels deep.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new Diagnostic('InvalidDataValue', 'Non-finite numbers cannot cross the data boundary.');
            }

            return $value;
        }

        if (is_array($value)) {
            if (count($value) > self::MAX_COLLECTION_ITEMS) {
                throw new Diagnostic('CollectionLimitExceeded', 'A value may contain at most '.self::MAX_COLLECTION_ITEMS.' items.');
            }

            return array_map(fn (mixed $item): mixed => $this->dataValue($item, $depth + 1), $value);
        }

        throw new Diagnostic('InvalidDataValue', get_debug_type($value).' values cannot cross the data boundary.');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function notifyToolCallEnd(
        string $id,
        int $index,
        string $path,
        array $input,
        int $started,
        string $outcome,
        ?string $message = null,
    ): void {
        if ($this->onToolCallEnd === null) {
            return;
        }

        ($this->onToolCallEnd)(array_filter([
            'id' => $id,
            'index' => $index,
            'name' => $path,
            'input' => $input,
            'durationMs' => (hrtime(true) - $started) / 1e6,
            'outcome' => $outcome,
            'message' => $message,
        ], fn (mixed $value): bool => $value !== null));
    }

    protected function tick(): void
    {
        if (++$this->operations > $this->maxOperations) {
            throw new Diagnostic('OperationLimitExceeded', sprintf(
                'The program exceeded the limit of %d operations.', $this->maxOperations
            ));
        }

        if (hrtime(true) / 1e9 > $this->deadline) {
            throw new Diagnostic('TimeoutExceeded', sprintf(
                'The program exceeded the %s second execution deadline.', $this->timeout
            ));
        }
    }
}
