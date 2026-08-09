<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use ErrorException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Throwable;

/**
 * A confined tree-walking evaluator for a bounded PHP subset — no eval, no ambient authority.
 *
 * Programs may only touch plain data (scalars and arrays) and the supplied tools; expected
 * failures are returned as diagnostic data instead of being thrown to the host.
 */
class Interpreter
{
    /**
     * Built-in functions invoked directly on the host engine after argument evaluation.
     *
     * @var array<int, string>
     */
    public const FUNCTIONS = [
        'abs', 'array_chunk', 'array_column', 'array_combine', 'array_diff', 'array_diff_key',
        'array_fill', 'array_fill_keys', 'array_filter', 'array_flip', 'array_intersect',
        'array_intersect_key', 'array_key_exists', 'array_key_first', 'array_key_last', 'array_keys',
        'array_map', 'array_merge', 'array_pad', 'array_product', 'array_reduce', 'array_reverse',
        'array_search', 'array_slice', 'array_sum', 'array_unique', 'array_values', 'boolval', 'ceil',
        'count', 'explode', 'floatval', 'floor', 'fmod', 'gettype', 'implode', 'in_array', 'intdiv',
        'intval', 'is_array', 'is_bool', 'is_float', 'is_int', 'is_null', 'is_numeric', 'is_string',
        'join', 'json_decode', 'json_encode', 'lcfirst', 'ltrim', 'max', 'min', 'number_format',
        'pow', 'preg_quote', 'preg_replace', 'preg_replace_callback', 'preg_split', 'range', 'round',
        'rtrim', 'sprintf', 'sqrt', 'str_contains', 'str_ends_with', 'str_ireplace', 'str_pad',
        'str_repeat', 'str_replace', 'str_split', 'str_starts_with', 'strcasecmp', 'strcmp',
        'stripos', 'strlen', 'strpos', 'strrev', 'strtolower', 'strtoupper', 'strval', 'substr',
        'substr_count', 'trim', 'ucfirst', 'ucwords', 'wordwrap',
    ];

    /**
     * Built-ins that mutate their first argument (or fill their third, for preg matches) by reference.
     *
     * @var array<int, string>
     */
    public const REF_FUNCTIONS = [
        'array_pop', 'array_push', 'array_shift', 'array_unshift', 'arsort', 'asort', 'krsort',
        'ksort', 'preg_match', 'preg_match_all', 'rsort', 'sort', 'uasort', 'uksort', 'usort',
    ];

    /**
     * Native constants a program may reference by name.
     *
     * @var array<int, string>
     */
    public const CONSTANTS = [
        'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_FLOAT_EPSILON', 'M_PI',
        'JSON_PRETTY_PRINT', 'JSON_UNESCAPED_SLASHES', 'JSON_UNESCAPED_UNICODE',
        'SORT_REGULAR', 'SORT_NUMERIC', 'SORT_STRING', 'SORT_FLAG_CASE',
        'ARRAY_FILTER_USE_KEY', 'ARRAY_FILTER_USE_BOTH',
        'PREG_PATTERN_ORDER', 'PREG_SET_ORDER', 'PREG_OFFSET_CAPTURE',
        'PREG_SPLIT_NO_EMPTY', 'PREG_SPLIT_DELIM_CAPTURE',
        'STR_PAD_LEFT', 'STR_PAD_RIGHT', 'STR_PAD_BOTH', 'COUNT_NORMAL', 'COUNT_RECURSIVE',
    ];

    /**
     * Exception class names a program may construct and catch.
     *
     * @var array<int, string>
     */
    public const EXCEPTIONS = [
        'Exception', 'Error', 'RuntimeException', 'InvalidArgumentException', 'LogicException',
        'ValueError', 'TypeError', 'DivisionByZeroError', 'UnhandledMatchError', 'JsonException',
    ];

    /**
     * Values crossing the model-facing data boundary may nest at most this deep.
     */
    protected const MAX_DEPTH = 32;

    /**
     * User-defined closure calls may nest at most this deep.
     */
    protected const MAX_CALL_DEPTH = 128;

    /**
     * Captured echo output, returned to the model as logs.
     *
     * @var array<int, string>
     */
    protected array $logs = [];

    /**
     * The tool paths invoked during this execution, in call order.
     *
     * @var array<int, string>
     */
    protected array $toolCalls = [];

    /**
     * User-defined functions declared at the top level of the program.
     *
     * @var array<string, ClosureValue>
     */
    protected array $functions = [];

    protected ?float $deadline = null;

    protected int $callDepth = 0;

    protected Catalog $catalog;

    /**
     * @param  Catalog|array<string, Tool>  $catalog
     */
    public function __construct(
        Catalog|array $catalog = [],
        protected int|float|null $timeout = null,
        protected ?int $maxToolCalls = null,
        protected ?Closure $onToolCallStart = null,
        protected ?Closure $onToolCallEnd = null,
    ) {
        $this->catalog = $catalog instanceof Catalog ? $catalog : new Catalog($catalog);
    }

    /**
     * Execute the given program and return its result or failure as data.
     *
     * @return array<string, mixed>
     */
    public function execute(string $code): array
    {
        $this->logs = [];
        $this->toolCalls = [];
        $this->functions = [];
        $this->callDepth = 0;
        $this->deadline = $this->timeout !== null ? hrtime(true) / 1e9 + $this->timeout : null;

        try {
            $statements = $this->parse($code);
        } catch (ParseError $e) {
            return $this->failure('ParseError', 'The program could not be parsed: '.$e->getMessage());
        }

        $scope = new Scope;

        try {
            foreach ($statements as $statement) {
                if ($statement instanceof Stmt\Function_) {
                    $this->declareFunction($statement, $scope);
                }
            }

            $value = null;

            try {
                $this->execStmts($statements, $scope);
            } catch (ReturnSignal $signal) {
                $value = $signal->value;
            } catch (BreakSignal|ContinueSignal) {
                throw new Diagnostic('ExecutionFailure', 'break/continue used outside of a loop.');
            }

            return [
                'ok' => true,
                'value' => $this->toDataValue($value),
                'logs' => $this->logs,
                'toolCalls' => $this->toolCalls,
            ];
        } catch (ProgramThrow $thrown) {
            return $this->failure('ExecutionFailure', sprintf(
                'Uncaught %s: %s', $thrown->value->name, $thrown->value->message
            ));
        } catch (Diagnostic $diagnostic) {
            return $this->failure($diagnostic->kind, $diagnostic->getMessage());
        }
    }

    /**
     * Build a failure result carrying whatever executed before the error.
     *
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
     * Parse the program, tolerating an optional leading PHP open tag.
     *
     * @return array<int, Stmt>
     */
    protected function parse(string $code): array
    {
        if (str_starts_with(ltrim($code), '<?php')) {
            $code = preg_replace('/<\?php/', '', $code, 1);
        }

        $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code);

        return $statements ?? [];
    }

    /**
     * Abort with a timeout diagnostic once the deadline passes.
     */
    protected function tick(): void
    {
        if ($this->deadline !== null && hrtime(true) / 1e9 > $this->deadline) {
            throw new Diagnostic('TimeoutExceeded', sprintf(
                'Execution exceeded the %s second timeout.', $this->timeout
            ));
        }
    }

    /**
     * Execute a list of statements in the given scope.
     *
     * @param  array<int, Stmt>  $statements
     */
    protected function execStmts(array $statements, Scope $scope): void
    {
        foreach ($statements as $statement) {
            $this->execStmt($statement, $scope);
        }
    }

    /**
     * Execute a single statement.
     */
    protected function execStmt(Stmt $statement, Scope $scope): void
    {
        $this->tick();

        match (true) {
            $statement instanceof Stmt\Expression => $this->eval($statement->expr, $scope),
            $statement instanceof Stmt\Echo_ => $this->execEcho($statement, $scope),
            $statement instanceof Stmt\Return_ => throw new ReturnSignal(
                $statement->expr === null ? null : $this->eval($statement->expr, $scope)
            ),
            $statement instanceof Stmt\If_ => $this->execIf($statement, $scope),
            $statement instanceof Stmt\Foreach_ => $this->execForeach($statement, $scope),
            $statement instanceof Stmt\While_ => $this->execWhile($statement, $scope),
            $statement instanceof Stmt\Do_ => $this->execDoWhile($statement, $scope),
            $statement instanceof Stmt\For_ => $this->execFor($statement, $scope),
            $statement instanceof Stmt\Switch_ => $this->execSwitch($statement, $scope),
            $statement instanceof Stmt\Break_ => throw new BreakSignal($this->loopLevels($statement->num, $scope)),
            $statement instanceof Stmt\Continue_ => throw new ContinueSignal($this->loopLevels($statement->num, $scope)),
            $statement instanceof Stmt\TryCatch => $this->execTryCatch($statement, $scope),
            $statement instanceof Stmt\Function_ => $this->declareFunction($statement, $scope),
            $statement instanceof Stmt\Block => $this->execStmts($statement->stmts, $scope),
            $statement instanceof Stmt\Nop => null,
            default => throw $this->unsupported($statement),
        };
    }

    /**
     * Capture echo output as logs.
     */
    protected function execEcho(Stmt\Echo_ $statement, Scope $scope): void
    {
        $this->logs[] = implode('', array_map(
            fn (Expr $expr): string => $this->stringify($this->eval($expr, $scope)),
            $statement->exprs,
        ));
    }

    protected function execIf(Stmt\If_ $statement, Scope $scope): void
    {
        if ($this->eval($statement->cond, $scope)) {
            $this->execStmts($statement->stmts, $scope);

            return;
        }

        foreach ($statement->elseifs as $elseif) {
            if ($this->eval($elseif->cond, $scope)) {
                $this->execStmts($elseif->stmts, $scope);

                return;
            }
        }

        if ($statement->else !== null) {
            $this->execStmts($statement->else->stmts, $scope);
        }
    }

    protected function execForeach(Stmt\Foreach_ $statement, Scope $scope): void
    {
        if ($statement->byRef) {
            throw $this->unsupported($statement, 'foreach by reference');
        }

        $subject = $this->eval($statement->expr, $scope);

        if (! is_array($subject)) {
            throw new ProgramThrow(new ExceptionValue('TypeError', 'foreach() expects an array, '.get_debug_type($subject).' given.'));
        }

        foreach ($subject as $key => $value) {
            $this->tick();

            if ($statement->keyVar !== null) {
                $this->assignTo($statement->keyVar, $key, $scope);
            }

            $this->assignTo($statement->valueVar, $value, $scope);

            if (! $this->runLoopBody($statement->stmts, $scope)) {
                break;
            }
        }
    }

    protected function execWhile(Stmt\While_ $statement, Scope $scope): void
    {
        while ($this->eval($statement->cond, $scope)) {
            $this->tick();

            if (! $this->runLoopBody($statement->stmts, $scope)) {
                break;
            }
        }
    }

    protected function execDoWhile(Stmt\Do_ $statement, Scope $scope): void
    {
        do {
            $this->tick();

            if (! $this->runLoopBody($statement->stmts, $scope)) {
                break;
            }
        } while ($this->eval($statement->cond, $scope));
    }

    protected function execFor(Stmt\For_ $statement, Scope $scope): void
    {
        foreach ($statement->init as $init) {
            $this->eval($init, $scope);
        }

        while (true) {
            $this->tick();

            foreach ($statement->cond as $condition) {
                if (! $this->eval($condition, $scope)) {
                    return;
                }
            }

            if (! $this->runLoopBody($statement->stmts, $scope)) {
                return;
            }

            foreach ($statement->loop as $loop) {
                $this->eval($loop, $scope);
            }
        }
    }

    protected function execSwitch(Stmt\Switch_ $statement, Scope $scope): void
    {
        $subject = $this->eval($statement->cond, $scope);
        $matched = false;

        try {
            foreach ($statement->cases as $case) {
                if (! $matched) {
                    $matched = $case->cond === null || $this->eval($case->cond, $scope) == $subject;
                }

                if ($matched) {
                    $this->execStmts($case->stmts, $scope);
                }
            }
        } catch (BreakSignal $signal) {
            if ($signal->levels > 1) {
                throw new BreakSignal($signal->levels - 1);
            }
        }
    }

    /**
     * Run one loop iteration, returning false when the loop should stop.
     */
    protected function runLoopBody(array $statements, Scope $scope): bool
    {
        try {
            $this->execStmts($statements, $scope);
        } catch (BreakSignal $signal) {
            if ($signal->levels > 1) {
                throw new BreakSignal($signal->levels - 1);
            }

            return false;
        } catch (ContinueSignal $signal) {
            if ($signal->levels > 1) {
                throw new ContinueSignal($signal->levels - 1);
            }
        }

        return true;
    }

    /**
     * Resolve the level count on a break/continue statement.
     */
    protected function loopLevels(?Expr $num, Scope $scope): int
    {
        if ($num === null) {
            return 1;
        }

        $levels = $this->eval($num, $scope);

        return is_int($levels) && $levels > 0 ? $levels : 1;
    }

    protected function execTryCatch(Stmt\TryCatch $statement, Scope $scope): void
    {
        try {
            try {
                $this->execStmts($statement->stmts, $scope);
            } catch (ProgramThrow $thrown) {
                $this->execCatch($statement, $thrown->value, $scope);
            } catch (Diagnostic $diagnostic) {
                if (! $diagnostic->catchable) {
                    throw $diagnostic;
                }

                $this->execCatch($statement, new ExceptionValue('Error', $diagnostic->getMessage()), $scope);
            }
        } finally {
            if ($statement->finally !== null) {
                $this->execStmts($statement->finally->stmts, $scope);
            }
        }
    }

    /**
     * Dispatch a caught error value to the first matching catch clause, or rethrow it.
     */
    protected function execCatch(Stmt\TryCatch $statement, ExceptionValue $error, Scope $scope): void
    {
        foreach ($statement->catches as $catch) {
            foreach ($catch->types as $type) {
                if (! $this->catchMatches($type->toString(), $error->name)) {
                    continue;
                }

                if ($catch->var !== null) {
                    $this->assignTo($catch->var, $error, $scope);
                }

                $this->execStmts($catch->stmts, $scope);

                return;
            }
        }

        throw new ProgramThrow($error);
    }

    /**
     * Determine whether a catch type matches a thrown error name.
     */
    protected function catchMatches(string $type, string $errorName): bool
    {
        $type = ltrim($type, '\\');

        // ponytail: broad hierarchy — Throwable/Exception/Error catch everything, otherwise exact name.
        return in_array($type, ['Throwable', 'Exception', 'Error'], true) || $type === $errorName;
    }

    /**
     * Register a top-level user-defined function.
     */
    protected function declareFunction(Stmt\Function_ $statement, Scope $scope): void
    {
        $this->functions[strtolower($statement->name->toString())] = new ClosureValue(
            $statement->params, $statement->stmts, [],
        );
    }

    /**
     * Evaluate an expression to a value.
     */
    protected function eval(Expr $expr, Scope $scope): mixed
    {
        return match (true) {
            $expr instanceof Scalar\Int_,
            $expr instanceof Scalar\Float_,
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\InterpolatedString => $this->evalInterpolated($expr, $scope),
            $expr instanceof Expr\ConstFetch => $this->evalConstant($expr),
            $expr instanceof Expr\Variable => $this->evalVariable($expr, $scope),
            $expr instanceof Expr\Array_ => $this->evalArray($expr, $scope),
            $expr instanceof Expr\ArrayDimFetch => $this->evalDimFetch($expr, $scope),
            $expr instanceof Expr\Assign => $this->assignTo($expr->var, $this->eval($expr->expr, $scope), $scope),
            $expr instanceof Expr\AssignOp => $this->evalAssignOp($expr, $scope),
            $expr instanceof Expr\BinaryOp => $this->evalBinaryOp($expr, $scope),
            $expr instanceof Expr\UnaryMinus => $this->arithmetic(fn (): int|float => -$this->expectNumber($this->eval($expr->expr, $scope))),
            $expr instanceof Expr\UnaryPlus => $this->expectNumber($this->eval($expr->expr, $scope)),
            $expr instanceof Expr\BooleanNot => ! $this->eval($expr->expr, $scope),
            $expr instanceof Expr\BitwiseNot => $this->arithmetic(fn (): int => ~$this->expectNumber($this->eval($expr->expr, $scope))),
            $expr instanceof Expr\Ternary => $this->evalTernary($expr, $scope),
            $expr instanceof Expr\Match_ => $this->evalMatch($expr, $scope),
            $expr instanceof Expr\FuncCall => $this->evalFuncCall($expr, $scope),
            $expr instanceof Expr\Closure => $this->evalClosure($expr, $scope),
            $expr instanceof Expr\ArrowFunction => new ClosureValue($expr->params, $expr->expr, $scope->vars),
            $expr instanceof Expr\Isset_ => $this->evalIsset($expr, $scope),
            $expr instanceof Expr\Empty_ => empty($this->evalNullable($expr->expr, $scope)),
            $expr instanceof Expr\PostInc, $expr instanceof Expr\PostDec,
            $expr instanceof Expr\PreInc, $expr instanceof Expr\PreDec => $this->evalIncDec($expr, $scope),
            $expr instanceof Expr\Throw_ => throw new ProgramThrow($this->toExceptionValue($this->eval($expr->expr, $scope))),
            $expr instanceof Expr\New_ => $this->evalNew($expr, $scope),
            $expr instanceof Expr\MethodCall => $this->evalMethodCall($expr, $scope),
            $expr instanceof Expr\Cast => $this->evalCast($expr, $scope),
            default => throw $this->unsupported($expr),
        };
    }

    protected function evalInterpolated(Scalar\InterpolatedString $expr, Scope $scope): string
    {
        $result = '';

        foreach ($expr->parts as $part) {
            $result .= $part instanceof Node\InterpolatedStringPart
                ? $part->value
                : $this->stringify($this->eval($part, $scope));
        }

        return $result;
    }

    protected function evalConstant(Expr\ConstFetch $expr): mixed
    {
        $name = $expr->name->toString();

        return match (strtolower($name)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => in_array($name, self::CONSTANTS, true)
                ? constant($name)
                : throw new ProgramThrow(new ExceptionValue('Error', sprintf('Undefined constant "%s".', $name))),
        };
    }

    protected function evalVariable(Expr\Variable $expr, Scope $scope): mixed
    {
        $name = $this->variableName($expr);

        if (! array_key_exists($name, $scope->vars)) {
            throw new ProgramThrow(new ExceptionValue('Error', sprintf('Undefined variable $%s.', $name)));
        }

        return $scope->vars[$name];
    }

    protected function evalArray(Expr\Array_ $expr, Scope $scope): array
    {
        $result = [];

        foreach ($expr->items as $item) {
            if ($item->byRef) {
                throw $this->unsupported($item, 'array items by reference');
            }

            if ($item->unpack) {
                $spread = $this->eval($item->value, $scope);

                if (! is_array($spread)) {
                    throw new ProgramThrow(new ExceptionValue('TypeError', 'Only arrays can be spread.'));
                }

                foreach ($spread as $key => $value) {
                    is_int($key) ? $result[] = $value : $result[$key] = $value;
                }

                continue;
            }

            $value = $this->eval($item->value, $scope);

            if ($item->key === null) {
                $result[] = $value;
            } else {
                $result[$this->dimKey($this->eval($item->key, $scope))] = $value;
            }
        }

        return $result;
    }

    protected function evalDimFetch(Expr\ArrayDimFetch $expr, Scope $scope): mixed
    {
        if ($expr->dim === null) {
            throw $this->unsupported($expr, 'reading an array append expression');
        }

        $subject = $this->eval($expr->var, $scope);
        $key = $this->dimKey($this->eval($expr->dim, $scope));

        if (is_string($subject)) {
            if (! is_int($key) || ! isset($subject[$key])) {
                throw new ProgramThrow(new ExceptionValue('Error', sprintf('Undefined string offset %s.', json_encode($key))));
            }

            return $subject[$key];
        }

        if (! is_array($subject)) {
            throw new ProgramThrow(new ExceptionValue('TypeError', 'Cannot index a '.get_debug_type($subject).' value.'));
        }

        if (! array_key_exists($key, $subject)) {
            throw new ProgramThrow(new ExceptionValue('Error', sprintf('Undefined array key %s.', json_encode($key))));
        }

        return $subject[$key];
    }

    protected function evalAssignOp(Expr\AssignOp $expr, Scope $scope): mixed
    {
        if ($expr instanceof Expr\AssignOp\Coalesce) {
            $current = $this->evalNullable($expr->var, $scope);

            return $current ?? $this->assignTo($expr->var, $this->eval($expr->expr, $scope), $scope);
        }

        $current = $this->eval($expr->var, $scope);
        $operand = $this->eval($expr->expr, $scope);

        $value = $this->arithmetic(fn (): mixed => match (true) {
            $expr instanceof Expr\AssignOp\Plus => $current + $operand,
            $expr instanceof Expr\AssignOp\Minus => $current - $operand,
            $expr instanceof Expr\AssignOp\Mul => $current * $operand,
            $expr instanceof Expr\AssignOp\Div => $current / $operand,
            $expr instanceof Expr\AssignOp\Mod => $current % $operand,
            $expr instanceof Expr\AssignOp\Pow => $current ** $operand,
            $expr instanceof Expr\AssignOp\Concat => $this->stringify($current).$this->stringify($operand),
            $expr instanceof Expr\AssignOp\BitwiseAnd => $current & $operand,
            $expr instanceof Expr\AssignOp\BitwiseOr => $current | $operand,
            $expr instanceof Expr\AssignOp\BitwiseXor => $current ^ $operand,
            $expr instanceof Expr\AssignOp\ShiftLeft => $current << $operand,
            $expr instanceof Expr\AssignOp\ShiftRight => $current >> $operand,
            default => throw $this->unsupported($expr),
        });

        return $this->assignTo($expr->var, $value, $scope);
    }

    protected function evalBinaryOp(Expr\BinaryOp $expr, Scope $scope): mixed
    {
        if ($expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\LogicalAnd) {
            return $this->eval($expr->left, $scope) && $this->eval($expr->right, $scope);
        }

        if ($expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\LogicalOr) {
            return $this->eval($expr->left, $scope) || $this->eval($expr->right, $scope);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->evalNullable($expr->left, $scope) ?? $this->eval($expr->right, $scope);
        }

        $left = $this->eval($expr->left, $scope);
        $right = $this->eval($expr->right, $scope);

        return $this->arithmetic(fn (): mixed => match (true) {
            $expr instanceof Expr\BinaryOp\Plus => $left + $right,
            $expr instanceof Expr\BinaryOp\Minus => $left - $right,
            $expr instanceof Expr\BinaryOp\Mul => $left * $right,
            $expr instanceof Expr\BinaryOp\Div => $left / $right,
            $expr instanceof Expr\BinaryOp\Mod => $left % $right,
            $expr instanceof Expr\BinaryOp\Pow => $left ** $right,
            $expr instanceof Expr\BinaryOp\Concat => $this->stringify($left).$this->stringify($right),
            $expr instanceof Expr\BinaryOp\Equal => $left == $right,
            $expr instanceof Expr\BinaryOp\NotEqual => $left != $right,
            $expr instanceof Expr\BinaryOp\Identical => $left === $right,
            $expr instanceof Expr\BinaryOp\NotIdentical => $left !== $right,
            $expr instanceof Expr\BinaryOp\Smaller => $left < $right,
            $expr instanceof Expr\BinaryOp\SmallerOrEqual => $left <= $right,
            $expr instanceof Expr\BinaryOp\Greater => $left > $right,
            $expr instanceof Expr\BinaryOp\GreaterOrEqual => $left >= $right,
            $expr instanceof Expr\BinaryOp\Spaceship => $left <=> $right,
            $expr instanceof Expr\BinaryOp\LogicalXor => $left xor $right,
            $expr instanceof Expr\BinaryOp\BitwiseAnd => $left & $right,
            $expr instanceof Expr\BinaryOp\BitwiseOr => $left | $right,
            $expr instanceof Expr\BinaryOp\BitwiseXor => $left ^ $right,
            $expr instanceof Expr\BinaryOp\ShiftLeft => $left << $right,
            $expr instanceof Expr\BinaryOp\ShiftRight => $left >> $right,
            default => throw $this->unsupported($expr),
        });
    }

    protected function evalTernary(Expr\Ternary $expr, Scope $scope): mixed
    {
        $condition = $this->eval($expr->cond, $scope);

        if ($expr->if === null) {
            return $condition ?: $this->eval($expr->else, $scope);
        }

        return $condition ? $this->eval($expr->if, $scope) : $this->eval($expr->else, $scope);
    }

    protected function evalMatch(Expr\Match_ $expr, Scope $scope): mixed
    {
        $subject = $this->eval($expr->cond, $scope);

        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                return $this->eval($arm->body, $scope);
            }

            foreach ($arm->conds as $condition) {
                if ($this->eval($condition, $scope) === $subject) {
                    return $this->eval($arm->body, $scope);
                }
            }
        }

        throw new ProgramThrow(new ExceptionValue('UnhandledMatchError', 'Unhandled match case.'));
    }

    protected function evalIsset(Expr\Isset_ $expr, Scope $scope): bool
    {
        foreach ($expr->vars as $var) {
            if ($this->evalNullable($var, $scope) === null) {
                return false;
            }
        }

        return true;
    }

    protected function evalIncDec(Expr\PostInc|Expr\PostDec|Expr\PreInc|Expr\PreDec $expr, Scope $scope): mixed
    {
        $current = $this->expectNumber($this->eval($expr->var, $scope));
        $updated = $expr instanceof Expr\PostInc || $expr instanceof Expr\PreInc ? $current + 1 : $current - 1;

        $this->assignTo($expr->var, $updated, $scope);

        return $expr instanceof Expr\PostInc || $expr instanceof Expr\PostDec ? $current : $updated;
    }

    protected function evalNew(Expr\New_ $expr, Scope $scope): ExceptionValue
    {
        if (! $expr->class instanceof Node\Name) {
            throw $this->unsupported($expr, 'dynamic class instantiation');
        }

        $class = ltrim($expr->class->toString(), '\\');

        if (! in_array($class, self::EXCEPTIONS, true)) {
            throw $this->unsupported($expr, sprintf('new %s(...) — only exception classes may be constructed', $class));
        }

        $message = isset($expr->args[0]) && $expr->args[0] instanceof Node\Arg
            ? $this->stringify($this->eval($expr->args[0]->value, $scope))
            : '';

        return new ExceptionValue($class, $message);
    }

    protected function evalMethodCall(Expr\MethodCall $expr, Scope $scope): mixed
    {
        $subject = $this->eval($expr->var, $scope);

        if ($subject instanceof ExceptionValue && $expr->name instanceof Node\Identifier) {
            return match ($expr->name->toString()) {
                'getMessage' => $subject->message,
                default => throw $this->unsupported($expr, 'only getMessage() may be called on a caught exception'),
            };
        }

        throw $this->unsupported($expr, 'method calls — data is scalars and arrays');
    }

    protected function evalCast(Expr\Cast $expr, Scope $scope): mixed
    {
        $value = $this->eval($expr->expr, $scope);

        return match (true) {
            $expr instanceof Expr\Cast\Int_ => (int) $this->expectScalar($value),
            $expr instanceof Expr\Cast\Double => (float) $this->expectScalar($value),
            $expr instanceof Expr\Cast\String_ => $this->stringify($value),
            $expr instanceof Expr\Cast\Bool_ => (bool) $value,
            $expr instanceof Expr\Cast\Array_ => is_array($value) ? $value : ($value === null ? [] : [$value]),
            default => throw $this->unsupported($expr),
        };
    }

    protected function evalClosure(Expr\Closure $expr, Scope $scope): ClosureValue
    {
        $captured = [];

        foreach ($expr->uses as $use) {
            if ($use->byRef) {
                throw $this->unsupported($use, 'use (&$var) by reference');
            }

            $name = $this->variableName($use->var);

            $captured[$name] = $scope->vars[$name] ?? null;
        }

        return new ClosureValue($expr->params, $expr->stmts, $captured);
    }

    /**
     * Evaluate a function call: tool(), a built-in, a user-defined function, or a closure variable.
     */
    protected function evalFuncCall(Expr\FuncCall $expr, Scope $scope): mixed
    {
        if ($expr->name instanceof Expr) {
            $callee = $this->eval($expr->name, $scope);

            if (! $callee instanceof ClosureValue) {
                throw new ProgramThrow(new ExceptionValue('TypeError', 'Value is not callable.'));
            }

            return $this->callClosure($callee, $this->evalArgs($expr, $scope));
        }

        $name = strtolower($expr->name->toString());

        if ($name === 'tool') {
            return $this->callTool($expr, $scope);
        }

        if ($name === 'search_tools') {
            return $this->searchTools($this->evalArgs($expr, $scope));
        }

        if (in_array($name, self::REF_FUNCTIONS, true)) {
            return $this->callRefFunction($name, $expr, $scope);
        }

        if (in_array($name, self::FUNCTIONS, true)) {
            return $this->callBuiltin($name, $this->evalArgs($expr, $scope));
        }

        if (isset($this->functions[$name])) {
            return $this->callClosure($this->functions[$name], $this->evalArgs($expr, $scope));
        }

        throw new ProgramThrow(new ExceptionValue('Error', sprintf(
            'Function %s() is not available in code mode. Only the functions listed in the tool description exist.', $name
        )));
    }

    /**
     * Evaluate call arguments, rejecting named arguments and spreads.
     *
     * @return array<int, mixed>
     */
    protected function evalArgs(Expr\FuncCall|Expr\MethodCall $expr, Scope $scope): array
    {
        $values = [];

        foreach ($expr->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || $arg->byRef) {
                throw $this->unsupported($expr, 'named, unpacked, or by-reference arguments');
            }

            $values[] = $this->eval($arg->value, $scope);
        }

        return $values;
    }

    /**
     * Invoke a built-in on the host engine, converting failures and warnings into catchable errors.
     */
    protected function callBuiltin(string $name, array $arguments): mixed
    {
        // json_decode always produces arrays so results stay inside the plain-data value space.
        if ($name === 'json_decode') {
            $arguments = [$arguments[0] ?? null, true];
        }

        $arguments = array_map(fn (mixed $argument): mixed => $this->bridgeClosures($argument), $arguments);

        set_error_handler(fn (int $severity, string $message) => throw new ErrorException($message));

        try {
            return $name(...$arguments);
        } catch (Diagnostic|ProgramThrow $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ProgramThrow($this->fromNativeThrowable($error));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Invoke a built-in that mutates a variable by reference, writing the result back to the program.
     */
    protected function callRefFunction(string $name, Expr\FuncCall $expr, Scope $scope): mixed
    {
        $refIndex = in_array($name, ['preg_match', 'preg_match_all'], true) ? 2 : 0;

        $arguments = [];

        foreach ($expr->args as $index => $arg) {
            if (! $arg instanceof Node\Arg || $arg->name !== null || $arg->unpack) {
                throw $this->unsupported($expr, 'named or unpacked arguments');
            }

            if ($index === $refIndex) {
                continue;
            }

            $arguments[$index] = $this->bridgeClosures($this->eval($arg->value, $scope));
        }

        $target = $expr->args[$refIndex]->value ?? null;

        if ($refIndex === 2 && $target === null) {
            return $this->callBuiltin($name, array_values($arguments));
        }

        if (! $target instanceof Expr\Variable && ! $target instanceof Expr\ArrayDimFetch) {
            throw $this->unsupported($expr, sprintf('%s() requires a variable for its by-reference argument', $name));
        }

        $subject = $refIndex === 0 ? $this->evalNullable($target, $scope) ?? [] : [];

        $natives = [];

        for ($index = 0; $index < count($expr->args); $index++) {
            if ($index === $refIndex) {
                $natives[] = &$subject;
            } else {
                $natives[] = $arguments[$index];
            }
        }

        set_error_handler(fn (int $severity, string $message) => throw new ErrorException($message));

        try {
            $result = $name(...$natives);
        } catch (Diagnostic|ProgramThrow $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ProgramThrow($this->fromNativeThrowable($error));
        } finally {
            restore_error_handler();
        }

        $this->assignTo($target, $subject, $scope);

        return $result;
    }

    /**
     * Wrap program closures as native callables so built-ins like array_map can invoke them.
     */
    protected function bridgeClosures(mixed $value): mixed
    {
        return $value instanceof ClosureValue
            ? fn (mixed ...$arguments): mixed => $this->callClosure($value, $arguments)
            : $value;
    }

    /**
     * Invoke a program-defined closure or function.
     */
    protected function callClosure(ClosureValue $closure, array $arguments): mixed
    {
        $this->tick();

        if (++$this->callDepth > self::MAX_CALL_DEPTH) {
            $this->callDepth--;

            throw new Diagnostic('ExecutionFailure', 'Maximum function call depth exceeded.');
        }

        try {
            $scope = new Scope;
            $scope->vars = $closure->vars;

            foreach ($closure->params as $index => $param) {
                if ($param->byRef) {
                    throw $this->unsupported($param, 'by-reference parameters');
                }

                $name = $this->variableName($param->var);

                if ($param->variadic) {
                    $scope->vars[$name] = array_slice($arguments, $index);

                    break;
                }

                $scope->vars[$name] = array_key_exists($index, $arguments)
                    ? $arguments[$index]
                    : ($param->default !== null ? $this->eval($param->default, $scope) : null);
            }

            if ($closure->body instanceof Expr) {
                return $this->eval($closure->body, $scope);
            }

            try {
                $this->execStmts($closure->body, $scope);
            } catch (ReturnSignal $signal) {
                return $signal->value;
            }

            return null;
        } finally {
            $this->callDepth--;
        }
    }

    /**
     * Invoke a catalog tool through the model-facing tool() built-in.
     */
    /**
     * Rank catalog tools against a query, returning their paths and signatures.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<int, array<string, string>>
     */
    protected function searchTools(array $arguments): array
    {
        $query = $arguments[0] ?? '';
        $limit = $arguments[1] ?? 10;

        if (! is_string($query)) {
            throw new ProgramThrow(new ExceptionValue('TypeError', 'search_tools() expects a query string.'));
        }

        if (! is_int($limit) || $limit < 1) {
            throw new ProgramThrow(new ExceptionValue('ValueError', 'search_tools() expects a positive integer limit.'));
        }

        return $this->catalog->search($query, $limit);
    }

    protected function callTool(Expr\FuncCall $expr, Scope $scope): string
    {
        $arguments = $this->evalArgs($expr, $scope);

        $path = $arguments[0] ?? null;

        if (! is_string($path)) {
            throw new Diagnostic('UnknownTool', 'tool() expects a tool path string as its first argument.');
        }

        $tool = $this->catalog->tool($path);

        if ($tool === null) {
            throw new Diagnostic('UnknownTool', sprintf(
                'Unknown tool "%s". Available tools: %s.', $path, implode(', ', $this->catalog->paths())
            ));
        }

        if ($this->maxToolCalls !== null && count($this->toolCalls) >= $this->maxToolCalls) {
            throw new Diagnostic('ToolCallLimitExceeded', sprintf(
                'The program exceeded the limit of %d tool calls.', $this->maxToolCalls
            ));
        }

        $input = $arguments[1] ?? [];

        if (! is_array($input)) {
            throw new Diagnostic('InvalidToolInput', sprintf('tool(\'%s\', ...) arguments must be an array.', $path), catchable: true);
        }

        try {
            $input = $this->toDataValue($input);
        } catch (Diagnostic) {
            throw new Diagnostic('InvalidToolInput', sprintf('tool(\'%s\', ...) arguments must be plain data.', $path), catchable: true);
        }

        $index = count($this->toolCalls);
        $this->toolCalls[] = $path;

        if ($this->onToolCallStart !== null) {
            ($this->onToolCallStart)(['index' => $index, 'name' => $path, 'input' => $input]);
        }

        $started = hrtime(true);

        try {
            $result = (string) $tool->handle(new Request($input));
        } catch (Throwable $error) {
            $this->notifyToolCallEnd($index, $path, $input, $started, 'failure', $error->getMessage());

            throw new ProgramThrow(new ExceptionValue('Error', sprintf(
                'Tool "%s" failed: %s', $path, $error->getMessage()
            )));
        }

        $this->notifyToolCallEnd($index, $path, $input, $started, 'success');

        return $result;
    }

    /**
     * Fire the tool-call end hook when one is registered.
     */
    protected function notifyToolCallEnd(int $index, string $path, array $input, int $started, string $outcome, ?string $message = null): void
    {
        if ($this->onToolCallEnd === null) {
            return;
        }

        ($this->onToolCallEnd)(array_filter([
            'index' => $index,
            'name' => $path,
            'input' => $input,
            'durationMs' => (hrtime(true) - $started) / 1e6,
            'outcome' => $outcome,
            'message' => $message,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Assign a value to a variable or (possibly nested) array element.
     */
    protected function assignTo(Expr $target, mixed $value, Scope $scope): mixed
    {
        if ($target instanceof Expr\Variable) {
            $scope->vars[$this->variableName($target)] = $value;

            return $value;
        }

        if ($target instanceof Expr\ArrayDimFetch) {
            $container = &$this->containerRef($target->var, $scope);

            if ($container === null) {
                $container = [];
            }

            if (! is_array($container)) {
                throw new ProgramThrow(new ExceptionValue('TypeError', 'Cannot assign into a '.get_debug_type($container).' value.'));
            }

            if ($target->dim === null) {
                $container[] = $value;
            } else {
                $container[$this->dimKey($this->eval($target->dim, $scope))] = $value;
            }

            return $value;
        }

        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            throw $this->unsupported($target, 'list()/array destructuring — assign elements individually');
        }

        throw $this->unsupported($target, 'assignment to this expression');
    }

    /**
     * Resolve a reference to the container an array assignment writes into, auto-vivifying arrays.
     */
    protected function &containerRef(Expr $node, Scope $scope): mixed
    {
        if ($node instanceof Expr\Variable) {
            $name = $this->variableName($node);

            if (! array_key_exists($name, $scope->vars)) {
                $scope->vars[$name] = null;
            }

            return $scope->vars[$name];
        }

        if ($node instanceof Expr\ArrayDimFetch) {
            $parent = &$this->containerRef($node->var, $scope);

            if ($parent === null) {
                $parent = [];
            }

            if (! is_array($parent)) {
                throw new ProgramThrow(new ExceptionValue('TypeError', 'Cannot assign into a '.get_debug_type($parent).' value.'));
            }

            if ($node->dim === null) {
                throw $this->unsupported($node, 'nested array append in an assignment target');
            }

            $key = $this->dimKey($this->eval($node->dim, $scope));

            if (! array_key_exists($key, $parent)) {
                $parent[$key] = null;
            }

            return $parent[$key];
        }

        throw $this->unsupported($node, 'assignment to this expression');
    }

    /**
     * Evaluate an expression, yielding null instead of an error when a variable or key is missing.
     */
    protected function evalNullable(Expr $expr, Scope $scope): mixed
    {
        if ($expr instanceof Expr\Variable) {
            return $scope->vars[$this->variableName($expr)] ?? null;
        }

        if ($expr instanceof Expr\ArrayDimFetch && $expr->dim !== null) {
            $subject = $this->evalNullable($expr->var, $scope);

            if (is_string($subject)) {
                $key = $this->dimKey($this->eval($expr->dim, $scope));

                return is_int($key) && isset($subject[$key]) ? $subject[$key] : null;
            }

            if (! is_array($subject)) {
                return null;
            }

            return $subject[$this->dimKey($this->eval($expr->dim, $scope))] ?? null;
        }

        return $this->eval($expr, $scope);
    }

    /**
     * Run a native operation, converting engine errors and warnings into catchable program errors.
     */
    protected function arithmetic(Closure $operation): mixed
    {
        set_error_handler(fn (int $severity, string $message) => throw new ErrorException($message));

        try {
            return $operation();
        } catch (Diagnostic|ProgramThrow $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ProgramThrow($this->fromNativeThrowable($error));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Convert a native throwable into a program-visible error value.
     */
    protected function fromNativeThrowable(Throwable $error): ExceptionValue
    {
        $name = $error instanceof ErrorException ? 'Error' : class_basename($error);

        return new ExceptionValue($name, $error->getMessage());
    }

    /**
     * Coerce a thrown value into an error value.
     */
    protected function toExceptionValue(mixed $value): ExceptionValue
    {
        if ($value instanceof ExceptionValue) {
            return $value;
        }

        return new ExceptionValue('Error', $this->stringifyForError($value));
    }

    /**
     * Render a value as a string for string interpolation, concatenation, and echo.
     */
    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            is_array($value) => throw new ProgramThrow(new ExceptionValue('TypeError', 'Array to string conversion — use json_encode() instead.')),
            $value instanceof ExceptionValue => $value->name.': '.$value->message,
            default => throw new ProgramThrow(new ExceptionValue('TypeError', 'Cannot convert '.get_debug_type($value).' to string.')),
        };
    }

    /**
     * Render a thrown non-exception value into an error message without failing again.
     */
    protected function stringifyForError(mixed $value): string
    {
        return is_array($value) ? (json_encode($value) ?: 'array') : (string) $this->stringify($value);
    }

    /**
     * Require a numeric operand.
     */
    protected function expectNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        throw new ProgramThrow(new ExceptionValue('TypeError', 'Expected a number, got '.get_debug_type($value).'.'));
    }

    /**
     * Require a scalar (or null) for numeric casts.
     */
    protected function expectScalar(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new ProgramThrow(new ExceptionValue('TypeError', 'Cannot cast '.get_debug_type($value).'.'));
    }

    /**
     * Require a valid array key.
     */
    protected function dimKey(mixed $key): int|string
    {
        return match (true) {
            is_int($key), is_string($key) => $key,
            is_bool($key) => (int) $key,
            is_float($key) => (int) $key,
            $key === null => '',
            default => throw new ProgramThrow(new ExceptionValue('TypeError', 'Illegal array key of type '.get_debug_type($key).'.')),
        };
    }

    /**
     * Resolve a statically-named variable, rejecting variable variables.
     */
    protected function variableName(Expr $expr): string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }

        throw $this->unsupported($expr, 'variable variables');
    }

    /**
     * Enforce the plain-data boundary on values leaving the program.
     */
    protected function toDataValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new Diagnostic('InvalidDataValue', sprintf('Values may nest at most %d levels deep.', self::MAX_DEPTH));
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
            return array_map(fn (mixed $item): mixed => $this->toDataValue($item, $depth + 1), $value);
        }

        if ($value instanceof ExceptionValue) {
            return ['name' => $value->name, 'message' => $value->message];
        }

        throw new Diagnostic('InvalidDataValue', get_debug_type($value).' values cannot cross the data boundary.');
    }

    /**
     * Build an unsupported-syntax diagnostic pointing at the offending node.
     */
    protected function unsupported(Node $node, ?string $detail = null): Diagnostic
    {
        $line = $node->getStartLine();

        return new Diagnostic('UnsupportedSyntax', sprintf(
            'Unsupported syntax%s%s. Code mode runs a restricted PHP subset — see the tool description.',
            $detail !== null ? ': '.$detail : ' ('.$node->getType().')',
            $line > 0 ? ' on line '.$line : '',
        ));
    }
}
