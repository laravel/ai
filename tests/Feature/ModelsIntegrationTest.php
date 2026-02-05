<?php

namespace Tests\Feature;

use Laravel\Ai\Models;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;
use Tests\TestCase;

class ModelsIntegrationTest extends TestCase
{
    public function test_models_can_be_listed(): void
    {
        $response = Models::list();

        $this->assertInstanceOf(ModelListResponse::class, $response);
        $this->assertGreaterThan(0, count($response));
    }

    public function test_model_can_be_retrieved(): void
    {
        $response = Models::retrieve('gpt-5.2');

        $this->assertInstanceOf(ModelResponse::class, $response);
        $this->assertEquals('gpt-5.2', $response->id);
    }

    public function test_models_list_is_iterable(): void
    {
        $response = Models::list();

        $modelIds = [];
        foreach ($response as $model) {
            $this->assertInstanceOf(ModelResponse::class, $model);
            $modelIds[] = $model->id;
        }

        $this->assertGreaterThan(0, count($modelIds));
    }

    public function test_helper_function_works(): void
    {
        $response = ai_models();

        $this->assertInstanceOf(ModelListResponse::class, $response);
    }

    public function test_helper_function_for_single_model_works(): void
    {
        $response = ai_model('gpt-5.2');

        $this->assertInstanceOf(ModelResponse::class, $response);
    }
}
