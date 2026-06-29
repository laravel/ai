<?php

namespace Tests\Feature\Providers\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\Provider;

trait BedrockHelpers
{
    protected function fakeBedrockConverse(array $result): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result($result)]));
    }

    protected function fakeBedrockInvoke(array $body, array $headers = []): BedrockRuntimeClient
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
            fn (array $events) => new Result(['stream' => $events]),
            $eventLists,
        )));
    }

    protected function fakeBedrockConverseSequence(array $results): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn (array $result) => new Result($result),
            $results,
        )));
    }

    protected function bedrockClient(MockHandler $mock): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => false,
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

    protected function contentBlockStart(int $index, array $start = []): array
    {
        $payload = ['contentBlockIndex' => $index];

        if ($start) {
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
