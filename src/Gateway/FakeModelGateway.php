<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\ModelGateway;
use Laravel\Ai\Contracts\Providers\ModelProvider;
use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;
use RuntimeException;

class FakeModelGateway implements ModelGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayRequests = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * List all available models.
     */
    public function listModels(ModelProvider $provider, array $options = []): ModelListResponse
    {
        return $this->nextResponse('list', $provider, $options);
    }

    /**
     * Retrieve details of a specific model.
     */
    public function retrieveModel(ModelProvider $provider, string $modelId): ModelResponse
    {
        return $this->nextResponse('retrieve', $provider, $modelId);
    }

    /**
     * Delete a fine-tuned model.
     */
    public function deleteModel(ModelProvider $provider, string $modelId): ModelDeleteResponse
    {
        return $this->nextResponse('delete', $provider, $modelId);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(string $operation, ModelProvider $provider, mixed $data): mixed
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $operation, $data);

        return tap($this->marshalResponse(
            $response, $operation, $provider, $data
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        string $operation,
        ModelProvider $provider,
        mixed $data
    ): mixed {
        if ($response instanceof Closure) {
            $response = $response($operation, $data);
        }

        if (is_null($response)) {
            if ($this->preventStrayRequests) {
                throw new RuntimeException('Attempted model operation without a fake response.');
            }

            return $this->generateFakeResponse($operation, $provider, $data);
        }

        return $response;
    }

    /**
     * Generate a fake response based on the operation.
     */
    protected function generateFakeResponse(string $operation, ModelProvider $provider, mixed $data): mixed
    {
        return match ($operation) {
            'list' => new ModelListResponse([
                new ModelResponse('gpt-5.2', 'model', time(), 'openai'),
                new ModelResponse('gpt-5-nano', 'model', time(), 'openai'),
            ]),
            'retrieve' => new ModelResponse($data, 'model', time(), 'openai'),
            'delete' => new ModelDeleteResponse($data, 'model', true),
        };
    }

    /**
     * Indicate that an exception should be thrown if any model operations are not faked.
     */
    public function preventStrayRequests(bool $prevent = true): self
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }
}
