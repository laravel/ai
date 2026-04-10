<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Tools\Request;

test('invoke tool unwraps schema definition', function () {
    $tool = new class implements Tool
    {
        public array $receivedArguments = [];

        public function description(): Stringable|string
        {
            return 'Test tool';
        }

        public function handle(Request $request): Stringable|string
        {
            $this->receivedArguments = $request->all();

            return 'result';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $handler = new class
    {
        use AddsToolsToPrismRequests;

        protected $invokingToolCallback;

        protected $toolInvokedCallback;

        public function __construct()
        {
            $this->invokingToolCallback = fn () => null;
            $this->toolInvokedCallback = fn () => null;
        }

        public function callInvokeTool(Tool $tool, array $arguments): string
        {
            return $this->invokeTool($tool, $arguments);
        }
    };

    $handler->callInvokeTool($tool, ['schema_definition' => ['query' => 'test']]);

    expect($tool->receivedArguments)->toEqual(['query' => 'test']);
});

test('invoke tool falls back to raw arguments when schema definition is missing', function () {
    $tool = new class implements Tool
    {
        public array $receivedArguments = [];

        public function description(): Stringable|string
        {
            return 'Test tool';
        }

        public function handle(Request $request): Stringable|string
        {
            $this->receivedArguments = $request->all();

            return 'result';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $handler = new class
    {
        use AddsToolsToPrismRequests;

        protected $invokingToolCallback;

        protected $toolInvokedCallback;

        public function __construct()
        {
            $this->invokingToolCallback = fn () => null;
            $this->toolInvokedCallback = fn () => null;
        }

        public function callInvokeTool(Tool $tool, array $arguments): string
        {
            return $this->invokeTool($tool, $arguments);
        }
    };

    $handler->callInvokeTool($tool, ['query' => 'test']);

    expect($tool->receivedArguments)->toEqual(['query' => 'test']);
});

test('invoke tool handles empty arguments', function () {
    $tool = new class implements Tool
    {
        public array $receivedArguments = [];

        public function description(): Stringable|string
        {
            return 'Test tool';
        }

        public function handle(Request $request): Stringable|string
        {
            $this->receivedArguments = $request->all();

            return 'result';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $handler = new class
    {
        use AddsToolsToPrismRequests;

        protected $invokingToolCallback;

        protected $toolInvokedCallback;

        public function __construct()
        {
            $this->invokingToolCallback = fn () => null;
            $this->toolInvokedCallback = fn () => null;
        }

        public function callInvokeTool(Tool $tool, array $arguments): string
        {
            return $this->invokeTool($tool, $arguments);
        }
    };

    $handler->callInvokeTool($tool, []);

    expect($tool->receivedArguments)->toBeEmpty();
});
