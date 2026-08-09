<?php

namespace Laravel\Ai\CodeMode;

use PhpParser\Node;

/**
 * A program-defined closure or arrow function with its captured variables.
 */
class ClosureValue
{
    /**
     * @param  array<int, Node\Param>  $params
     * @param  array<int, Node\Stmt>|Node\Expr  $body
     * @param  array<string, mixed>  $vars
     */
    public function __construct(
        public readonly array $params,
        public readonly array|Node\Expr $body,
        public readonly array $vars,
    ) {}
}
