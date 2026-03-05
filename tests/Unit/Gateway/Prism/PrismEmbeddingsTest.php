<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\VoyageAiProvider;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Embeddings\PendingRequest;
use Prism\Prism\Embeddings\Response as PrismEmbeddingsResponse;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Tests\TestCase;

class PrismEmbeddingsTest extends TestCase
{
    #[DataProvider('providerDimensionOptionsProvider')]
    public function test_prism_gateway_passes_correct_dimension_options_for_provider(
        string $providerClass,
        string $driver,
        int $dimensions,
        array $expectedOptions,
        PrismProvider $prismProvider,
    ): void {
        $events = app(Dispatcher::class);
        $gateway = app(PrismGateway::class);

        $provider = new $providerClass(
            $gateway,
            ['driver' => $driver, 'key' => 'test-key', 'name' => $driver],
            $events,
        );

        $pendingRequest = Mockery::mock(PendingRequest::class);

        $pendingRequest->shouldReceive('using')
            ->with($prismProvider, 'test-model', Mockery::type('array'))
            ->once()
            ->andReturnSelf();

        $pendingRequest->shouldReceive('withProviderOptions')
            ->once()
            ->with($expectedOptions)
            ->andReturnSelf();

        $pendingRequest->shouldReceive('fromInput')
            ->andReturnSelf();

        $pendingRequest->shouldReceive('asEmbeddings')
            ->andReturn(new PrismEmbeddingsResponse(
                embeddings: [Embedding::fromArray(array_fill(0, $dimensions, 0.1))],
                usage: new EmbeddingsUsage(tokens: 10),
                meta: new Meta(id: '', model: 'test-model'),
            ));

        Prism::shouldReceive('embeddings')
            ->once()
            ->andReturn($pendingRequest);

        $response = $gateway->generateEmbeddings(
            $provider,
            'test-model',
            ['test input'],
            $dimensions
        );

        $this->assertCount($dimensions, $response->embeddings[0]);
    }

    public static function providerDimensionOptionsProvider(): array
    {
        return [
            'openai passes dimensions' => [
                OpenAiProvider::class, 'openai', 256, ['dimensions' => 256], PrismProvider::OpenAI,
            ],
            'gemini passes outputDimensionality' => [
                GeminiProvider::class, 'gemini', 256, ['outputDimensionality' => 256], PrismProvider::Gemini,
            ],
            'voyageai passes outputDimension' => [
                VoyageAiProvider::class, 'voyageai', 2048, ['outputDimension' => 2048], PrismProvider::VoyageAI,
            ],
        ];
    }
}
