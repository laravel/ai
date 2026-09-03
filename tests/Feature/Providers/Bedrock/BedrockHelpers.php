<?php

namespace Tests\Feature\Providers\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Laravel\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\Provider;

trait BedrockHelpers
{
    protected function fakeBedrockConverse(array $result): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result($result)]));
    }

    protected function fakeBedrockInvoke(array $body): BedrockRuntimeClient
    {
        return $this->bedrockClient($this->bedrockInvokeMock($body));
    }

    protected function bedrockInvokeMock(array|string $body): MockHandler
    {
        return new MockHandler([new Result([
            'body' => Utils::streamFor(is_string($body) ? $body : json_encode($body)),
        ])]);
    }

    protected function fakeBedrockInvokeWithHeaders(array $body, array $headers): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result([
            'body' => Utils::streamFor(json_encode($body)),
            '@metadata' => ['headers' => $headers],
        ])]));
    }

    protected function fakeBedrockStream(array $events): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([
            new Result(['stream' => $events]),
        ]));
    }

    protected function fakeBedrockStreamSequence(array $eventLists): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn (array $events): Result => new Result(['stream' => $events]),
            $eventLists,
        )));
    }

    protected function fakeBedrockConverseSequence(array $results): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn (array $result): Result => new Result($result),
            $results,
        )));
    }

    /**
     * Run a single generation step and return the parameters sent to the Converse API.
     */
    protected function capturedConverseParameters(?TextGenerationOptions $options = null, array $tools = [], ?string $instructions = 'You are a helpful assistant.'): array
    {
        $captured = [];

        $client = $this->bedrockClient(new MockHandler([function ($command) use (&$captured): Result {
            $captured = $command->toArray();

            return new Result([
                'output' => ['message' => ['content' => [['text' => 'Hello']]]],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
                'stopReason' => 'end_turn',
            ]);
        }]));

        (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            $instructions,
            tools: $tools,
            options: $options,
        );

        return $captured;
    }

    protected function bedrockClient(MockHandler $mock): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => false,
            'retries' => 0,
            'handler' => $mock,
        ]);
    }

    protected function gatewayWithClient(BedrockRuntimeClient $client): BedrockTextGateway
    {
        return new class($client) extends BedrockTextGateway
        {
            public function __construct(private BedrockRuntimeClient $stub)
            {
                parent::__construct();
            }

            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    protected function rerankingGatewayWithClient(BedrockRuntimeClient $client): BedrockRerankingGateway
    {
        return new class($client) extends BedrockRerankingGateway
        {
            public function __construct(private BedrockRuntimeClient $stub) {}

            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    protected function bedrockProvider(): BedrockProvider
    {
        return new BedrockProvider(
            config: [
                'name' => 'bedrock',
                'driver' => 'bedrock',
                'region' => 'us-east-1',
                'use_default_credential_provider' => false,
            ],
            events: app('events'),
        );
    }

    protected function mockBedrockException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
    {
        return new class($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException
        {
            public function __construct(
                private string $awsErrorCode,
                private int $httpStatus,
                string $message,
            ) {
                \Exception::__construct($message, $httpStatus);
            }

            public function getAwsErrorCode(): string
            {
                return $this->awsErrorCode;
            }

            public function getStatusCode(): int
            {
                return $this->httpStatus;
            }
        };
    }

    protected function contentBlockStart(int $index, array $start = []): array
    {
        $payload = ['contentBlockIndex' => $index];

        if ($start !== []) {
            $payload['start'] = $start;
        }

        return ['contentBlockStart' => $payload];
    }

    protected function contentBlockDelta(int $index, array $delta): array
    {
        return [
            'contentBlockDelta' => [
                'contentBlockIndex' => $index,
                'delta' => $delta,
            ],
        ];
    }

    protected function contentBlockStop(int $index): array
    {
        return [
            'contentBlockStop' => ['contentBlockIndex' => $index],
        ];
    }

    protected function messageStop(string $stopReason): array
    {
        return [
            'messageStop' => ['stopReason' => $stopReason],
        ];
    }
}
