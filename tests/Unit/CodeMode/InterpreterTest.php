<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\CodeMode\Catalog;
use Laravel\Ai\CodeMode\Interpreter;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

function interpreterTool(string $name, Closure $handler, string $description = 'A test tool.'): Tool
{
    return new class($name, $handler, $description) implements Tool
    {
        public function __construct(protected string $toolName, protected Closure $handler, protected string $toolDescription = 'A test tool.') {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return $this->toolDescription;
        }

        public function handle(Request $request): string
        {
            return (string) call_user_func($this->handler, $request);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

test('a program computes and returns plain data', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        $total = 0;

        foreach ([1, 2, 3, 4] as $number) {
            if ($number % 2 === 0) {
                $total += $number;
            }
        }

        return ['total' => $total, 'label' => "sum is {$total}"];
    PHP);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toBe(['total' => 6, 'label' => 'sum is 6']);
});

test('a program without a return produces null', function (): void {
    $result = (new Interpreter)->execute('$x = 1;');

    expect($result['ok'])->toBeTrue()->and($result['value'])->toBeNull();
});

test('a leading php open tag is tolerated', function (): void {
    $result = (new Interpreter)->execute("<?php\nreturn 1 + 1;");

    expect($result['ok'])->toBeTrue()->and($result['value'])->toBe(2);
});

test('closures, arrow functions, and user-defined functions work with array built-ins', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        function double($n) {
            return $n * 2;
        }

        $doubled = array_map(fn ($n) => double($n), [1, 2, 3]);
        $big = array_values(array_filter($doubled, function ($n) {
            return $n > 2;
        }));

        usort($big, fn ($a, $b) => $b <=> $a);

        return $big;
    PHP);

    expect($result['ok'])->toBeTrue()->and($result['value'])->toBe([6, 4]);
});

test('match, switch, ternaries, and null coalescing behave like PHP', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        $kind = match (2) {
            1 => 'one',
            2, 3 => 'few',
            default => 'many',
        };

        switch ($kind) {
            case 'few':
                $switched = 'matched';
                break;
            default:
                $switched = 'missed';
        }

        return [$kind, $switched, $missing ?? 'fallback', $kind === 'few' ? 'yes' : 'no'];
    PHP);

    expect($result['value'])->toBe(['few', 'matched', 'fallback', 'yes']);
});

test('nested array assignment, appends, and spread work', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        $data = [];
        $data['users']['alice']['age'] = 30;
        $data['tags'][] = 'a';
        $data['tags'][] = 'b';

        $merged = [...$data['tags'], 'c'];

        return [$data['users']['alice']['age'], $merged];
    PHP);

    expect($result['value'])->toBe([30, ['a', 'b', 'c']]);
});

test('programs call tools and results stay strings until decoded', function (): void {
    $orders = interpreterTool('lookup', fn (Request $request): string => json_encode([
        'id' => $request['id'],
        'status' => 'open',
    ]));

    $interpreter = new Interpreter(['orders.lookup' => $orders]);

    $result = $interpreter->execute(<<<'PHP'
        $order = json_decode(tool('orders.lookup', ['id' => 'order_42']));

        return ['id' => $order['id'], 'open' => $order['status'] === 'open'];
    PHP);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toBe(['id' => 'order_42', 'open' => true])
        ->and($result['toolCalls'])->toBe(['orders.lookup']);
});

test('json_decode always produces arrays', function (): void {
    $result = (new Interpreter)->execute('return json_decode(\'{"a":{"b":1}}\');');

    expect($result['value'])->toBe(['a' => ['b' => 1]]);
});

test('a tool failure is catchable inside the program', function (): void {
    $failing = interpreterTool('boom', fn () => throw new RuntimeException('The order service is down.'));

    $result = (new Interpreter(['boom' => $failing]))->execute(<<<'PHP'
        try {
            tool('boom', []);

            return 'unreachable';
        } catch (Exception $e) {
            return 'caught: ' . $e->getMessage();
        }
    PHP);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toContain('The order service is down.');
});

test('an uncaught tool failure fails the execution with partial tool calls retained', function (): void {
    $failing = interpreterTool('boom', fn () => throw new RuntimeException('nope'));

    $result = (new Interpreter(['boom' => $failing]))->execute('tool(\'boom\', []); return 1;');

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ExecutionFailure')
        ->and($result['error']['message'])->toContain('nope')
        ->and($result['toolCalls'])->toBe(['boom']);
});

test('an unknown tool is not catchable and lists the available paths', function (): void {
    $result = (new Interpreter(['orders.lookup' => interpreterTool('lookup', fn (): string => 'x')]))
        ->execute('try { tool("orders.missing", []); } catch (Exception $e) { return "swallowed"; }');

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('UnknownTool')
        ->and($result['error']['message'])->toContain('orders.lookup');
});

test('the tool call limit is enforced and not catchable', function (): void {
    $tool = interpreterTool('echo', fn (): string => 'ok');

    $result = (new Interpreter(['echo' => $tool], maxToolCalls: 2))->execute(<<<'PHP'
        try {
            for ($i = 0; $i < 5; $i++) {
                tool('echo', []);
            }
        } catch (Exception $e) {
            return 'swallowed';
        }
    PHP);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ToolCallLimitExceeded')
        ->and($result['toolCalls'])->toHaveCount(2);
});

test('a busy loop is interrupted by the timeout', function (): void {
    $result = (new Interpreter(timeout: 0.05))->execute('while (true) { $x = 1; }');

    expect($result['ok'])->toBeFalse()->and($result['error']['kind'])->toBe('TimeoutExceeded');
});

test('thrown exceptions carry their name through catch matching', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        try {
            throw new InvalidArgumentException('bad input');
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    PHP);

    expect($result['value'])->toBe('bad input');
});

test('native engine failures like division by zero are catchable', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        try {
            return 1 / 0;
        } catch (Exception $e) {
            return 'caught';
        }
    PHP);

    expect($result['value'])->toBe('caught');
});

test('echo output is captured as logs', function (): void {
    $result = (new Interpreter)->execute('echo "step one"; echo "step " . 2; return true;');

    expect($result['logs'])->toBe(['step one', 'step 2'])->and($result['value'])->toBeTrue();
});

test('preg_match fills its matches variable', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        preg_match('/order_(\d+)/', 'order_42', $matches);

        return $matches[1];
    PHP);

    expect($result['value'])->toBe('42');
});

test('by-reference sort built-ins write back to the program variable', function (): void {
    $result = (new Interpreter)->execute('$list = [3, 1, 2]; sort($list); return $list;');

    expect($result['value'])->toBe([1, 2, 3]);
});

test('array_push and array_pop mutate program arrays', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        $stack = [1];
        array_push($stack, 2, 3);
        $last = array_pop($stack);

        return [$stack, $last];
    PHP);

    expect($result['value'])->toBe([[1, 2], 3]);
});

test('unsupported syntax fails with a pointed diagnostic', function (): void {
    $result = (new Interpreter)->execute('class Foo {} return 1;');

    expect($result['ok'])->toBeFalse()->and($result['error']['kind'])->toBe('UnsupportedSyntax');
});

test('method calls on data are rejected', function (): void {
    $result = (new Interpreter)->execute('$x = "abc"; return $x->length();');

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('UnsupportedSyntax')
        ->and($result['error']['message'])->toContain('method calls');
});

test('unlisted functions are rejected with a catchable error', function (): void {
    $result = (new Interpreter)->execute('return file_get_contents("/etc/passwd");');

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ExecutionFailure')
        ->and($result['error']['message'])->toContain('file_get_contents');
});

test('a parse error is returned as data', function (): void {
    $result = (new Interpreter)->execute('return 1 +;');

    expect($result['ok'])->toBeFalse()->and($result['error']['kind'])->toBe('ParseError');
});

test('closures cannot cross the data boundary', function (): void {
    $result = (new Interpreter)->execute('return fn () => 1;');

    expect($result['ok'])->toBeFalse()->and($result['error']['kind'])->toBe('InvalidDataValue');
});

test('undefined variables fail loudly but are catchable', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        try {
            return $undefined + 1;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    PHP);

    expect($result['value'])->toContain('Undefined variable $undefined');
});

test('tool call hooks observe nested calls in order', function (): void {
    $events = [];

    $interpreter = new Interpreter(
        ['echo' => interpreterTool('echo', fn (): string => 'ok')],
        onToolCallStart: function (array $call) use (&$events): void {
            $events[] = 'start:'.$call['index'].':'.$call['name'];
        },
        onToolCallEnd: function (array $call) use (&$events): void {
            $events[] = 'end:'.$call['index'].':'.$call['outcome'];
        },
    );

    $interpreter->execute('tool(\'echo\', []); tool(\'echo\', []);');

    expect($events)->toBe(['start:0:echo', 'end:0:success', 'start:1:echo', 'end:1:success']);
});

test('the end hook reports failures with the model-safe message', function (): void {
    $ends = [];

    $interpreter = new Interpreter(
        ['boom' => interpreterTool('boom', fn () => throw new RuntimeException('down'))],
        onToolCallEnd: function (array $call) use (&$ends): void {
            $ends[] = $call;
        },
    );

    $interpreter->execute('try { tool(\'boom\', []); } catch (Exception $e) {} return 1;');

    expect($ends)->toHaveCount(1)
        ->and($ends[0]['outcome'])->toBe('failure')
        ->and($ends[0]['message'])->toBe('down');
});

test('string interpolation of arrays is a catchable type error', function (): void {
    $result = (new Interpreter)->execute('$a = [1]; return "value: $a";');

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ExecutionFailure')
        ->and($result['error']['message'])->toContain('json_encode');
});

test('break and continue support levels', function (): void {
    $result = (new Interpreter)->execute(<<<'PHP'
        $hits = [];

        foreach ([1, 2, 3] as $i) {
            foreach ([1, 2, 3] as $j) {
                if ($j === 2) {
                    continue 2;
                }

                if ($i === 3) {
                    break 2;
                }

                $hits[] = "$i.$j";
            }
        }

        return $hits;
    PHP);

    expect($result['value'])->toBe(['1.1', '2.1']);
});

test('search_tools ranks the catalog by term overlap', function (): void {
    $interpreter = new Interpreter(new Catalog([
        'orders.LookupOrder' => interpreterTool('LookupOrder', fn () => '', 'Look up an order.'),
        'mail.SendEmail' => interpreterTool('SendEmail', fn () => '', 'Send an email message.'),
        'orders.CancelOrder' => interpreterTool('CancelOrder', fn () => '', 'Cancel an order.'),
    ]));

    $result = $interpreter->execute("return search_tools('cancel order');");

    expect(array_column($result['value'], 'path'))
        ->toBe(['orders.CancelOrder', 'orders.LookupOrder'])
        ->and($result['value'][0]['signature'])->toContain('CancelOrder');
});

test('search_tools browses the catalog for an empty query and honors the limit', function (): void {
    $interpreter = new Interpreter(new Catalog([
        'a' => interpreterTool('a', fn () => ''),
        'b' => interpreterTool('b', fn () => ''),
        'c' => interpreterTool('c', fn () => ''),
    ]));

    expect(array_column($interpreter->execute("return search_tools('');")['value'], 'path'))
        ->toBe(['a', 'b', 'c'])
        ->and(array_column($interpreter->execute("return search_tools('', 2);")['value'], 'path'))
        ->toBe(['a', 'b']);
});

test('search_tools rejects an invalid limit', function (): void {
    $result = (new Interpreter)->execute("return search_tools('x', 0);");

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['message'])->toContain('positive integer limit');
});
