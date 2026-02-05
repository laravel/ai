<?php

namespace Tests\Feature;

use Laravel\Ai\Batches;
use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;
use Laravel\Ai\Responses\Data\BatchError;
use Laravel\Ai\Responses\Data\BatchRequestCounts;
use Laravel\Ai\Responses\Data\Meta;
use Tests\TestCase;

class BatchTest extends TestCase
{
    public function test_can_create_batch(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('createBatch')
            ->once()
            ->with('file-abc123', '/v1/chat/completions', '24h', [])
            ->andReturn($this->createBatchResponse('batch_abc123', 'validating'));

        $response = Batches::create('file-abc123', '/v1/chat/completions');

        $this->assertInstanceOf(BatchResponse::class, $response);
        $this->assertEquals('batch_abc123', $response->id);
        $this->assertEquals('validating', $response->status);
    }

    public function test_can_create_batch_with_options(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('createBatch')
            ->once()
            ->with('file-abc123', '/v1/chat/completions', '24h', ['metadata' => ['key' => 'value']])
            ->andReturn($this->createBatchResponse('batch_abc123', 'validating'));

        $response = Batches::create('file-abc123', '/v1/chat/completions', '24h', [
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertInstanceOf(BatchResponse::class, $response);
    }

    public function test_can_retrieve_batch(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('retrieveBatch')
            ->once()
            ->with('batch_abc123')
            ->andReturn($this->createBatchResponse('batch_abc123', 'completed'));

        $response = Batches::retrieve('batch_abc123');

        $this->assertInstanceOf(BatchResponse::class, $response);
        $this->assertEquals('batch_abc123', $response->id);
        $this->assertEquals('completed', $response->status);
    }

    public function test_can_cancel_batch(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('cancelBatch')
            ->once()
            ->with('batch_abc123')
            ->andReturn($this->createBatchResponse('batch_abc123', 'cancelling'));

        $response = Batches::cancel('batch_abc123');

        $this->assertInstanceOf(BatchResponse::class, $response);
        $this->assertEquals('batch_abc123', $response->id);
        $this->assertEquals('cancelling', $response->status);
    }

    public function test_can_list_batches(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('listBatches')
            ->once()
            ->with([])
            ->andReturn(new BatchListResponse(
                batches: [
                    $this->createBatchResponse('batch_1', 'completed'),
                    $this->createBatchResponse('batch_2', 'in_progress'),
                ],
                hasMore: false,
                firstId: 'batch_1',
                lastId: 'batch_2',
            ));

        $response = Batches::list();

        $this->assertInstanceOf(BatchListResponse::class, $response);
        $this->assertCount(2, $response);
        $this->assertFalse($response->hasMore);
    }

    public function test_can_list_batches_with_pagination(): void
    {
        $provider = $this->mockBatchProvider();

        $provider->expects('listBatches')
            ->once()
            ->with(['after' => 'batch_1', 'limit' => 10])
            ->andReturn(new BatchListResponse(
                batches: [
                    $this->createBatchResponse('batch_2', 'completed'),
                ],
                hasMore: true,
                firstId: 'batch_2',
                lastId: 'batch_2',
            ));

        $response = Batches::list(['after' => 'batch_1', 'limit' => 10]);

        $this->assertInstanceOf(BatchListResponse::class, $response);
        $this->assertCount(1, $response);
        $this->assertTrue($response->hasMore);
    }

    public function test_batch_response_to_array(): void
    {
        $response = $this->createBatchResponse('batch_abc123', 'completed');

        $array = $response->toArray();

        $this->assertEquals('batch_abc123', $array['id']);
        $this->assertEquals('batch', $array['object']);
        $this->assertEquals('completed', $array['status']);
        $this->assertIsArray($array['request_counts']);
        $this->assertIsArray($array['metadata']);
    }

    public function test_batch_list_response_is_iterable(): void
    {
        $response = new BatchListResponse(
            batches: [
                $this->createBatchResponse('batch_1', 'completed'),
                $this->createBatchResponse('batch_2', 'in_progress'),
            ],
            hasMore: false,
        );

        $ids = [];
        foreach ($response as $batch) {
            $ids[] = $batch->id;
        }

        $this->assertEquals(['batch_1', 'batch_2'], $ids);
    }

    public function test_batch_error_to_array(): void
    {
        $error = new BatchError(
            code: 'invalid_request',
            message: 'Invalid input',
            param: 'model',
            line: 5
        );

        $array = $error->toArray();

        $this->assertEquals('invalid_request', $array['code']);
        $this->assertEquals('Invalid input', $array['message']);
        $this->assertEquals('model', $array['param']);
        $this->assertEquals(5, $array['line']);
    }

    public function test_batch_request_counts_to_array(): void
    {
        $counts = new BatchRequestCounts(
            total: 100,
            completed: 75,
            failed: 5
        );

        $array = $counts->toArray();

        $this->assertEquals(100, $array['total']);
        $this->assertEquals(75, $array['completed']);
        $this->assertEquals(5, $array['failed']);
    }

    protected function mockBatchProvider()
    {
        $provider = \Mockery::mock(\Laravel\Ai\Contracts\Providers\BatchProvider::class);
        $this->app->instance(\Laravel\Ai\AiManager::class, \Mockery::mock(\Laravel\Ai\AiManager::class));
        $this->app->make(\Laravel\Ai\AiManager::class)
            ->expects('batchProvider')
            ->andReturn($provider);

        return $provider;
    }

    protected function createBatchResponse(string $id, string $status): BatchResponse
    {
        return new BatchResponse(
            id: $id,
            object: 'batch',
            endpoint: '/v1/chat/completions',
            inputFileId: 'file-abc123',
            outputFileId: $status === 'completed' ? 'file-output123' : null,
            errorFileId: null,
            status: $status,
            completionWindow: '24h',
            createdAt: 1609459200,
            completedAt: $status === 'completed' ? 1609545600 : null,
            failedAt: null,
            cancelledAt: null,
            expiresAt: 1609632000,
            requestCounts: new BatchRequestCounts(
                total: 100,
                completed: $status === 'completed' ? 100 : 50,
                failed: 0,
            ),
            errors: [],
            metadata: [],
            meta: new Meta('openai'),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }
}
