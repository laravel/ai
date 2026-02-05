<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeModelGateway;
use Laravel\Ai\Models;
use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;
use Tests\TestCase;

class ModelsFakeTest extends TestCase
{
    public function test_models_can_be_faked(): void
    {
        $fakeGateway = new FakeModelGateway([
            new ModelListResponse([
                new ModelResponse('gpt-test-1', 'model', time(), 'test-org'),
                new ModelResponse('gpt-test-2', 'model', time(), 'test-org'),
            ]),
        ]);

        $provider = Ai::modelProvider('openai');
        $provider->useModelGateway($fakeGateway);

        $response = $provider->listModels();

        $this->assertInstanceOf(ModelListResponse::class, $response);
        $this->assertEquals(2, count($response));
        $this->assertEquals('gpt-test-1', $response->first()->id);
    }

    public function test_model_retrieve_can_be_faked(): void
    {
        $fakeGateway = new FakeModelGateway([
            new ModelResponse('gpt-test-1', 'model', 1234567890, 'test-org'),
        ]);

        $provider = Ai::modelProvider('openai');
        $provider->useModelGateway($fakeGateway);

        $response = $provider->retrieveModel('gpt-test-1');

        $this->assertInstanceOf(ModelResponse::class, $response);
        $this->assertEquals('gpt-test-1', $response->id);
        $this->assertEquals('test-org', $response->ownedBy);
    }

    public function test_model_delete_can_be_faked(): void
    {
        $fakeGateway = new FakeModelGateway([
            new ModelDeleteResponse('gpt-test-1', 'model', true),
        ]);

        $provider = Ai::modelProvider('openai');
        $provider->useModelGateway($fakeGateway);

        $response = $provider->deleteModel('gpt-test-1');

        $this->assertInstanceOf(ModelDeleteResponse::class, $response);
        $this->assertEquals('gpt-test-1', $response->id);
        $this->assertTrue($response->deleted);
    }
}
