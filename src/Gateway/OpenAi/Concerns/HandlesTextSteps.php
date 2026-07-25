<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\TokenCountResponse;

trait HandlesTextSteps
{
    /**
     * Generate text for a single Responses API step.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema));
    }

    /**
     * Count the tokens the given messages will consume before inference.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, mixed>  $providerOptions
     */
    public function countTokens(
        TextProvider $provider,
        string $model,
        array $messages,
        ?string $instructions = null,
        array $tools = [],
        int $timeout = 30,
        array $providerOptions = [],
    ): TokenCountResponse {
        $body = [
            'model' => $model,
            'input' => $this->mapMessagesToInput($messages, $instructions, $provider),
        ];

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses/input_tokens', array_merge($body, $providerOptions)),
        );

        return new TokenCountResponse(
            (int) $response->json('input_tokens'),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Stream text for a single Responses API step.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);
        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Build the request body for the current text generation step.
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $stepContext->continuationToken && ! $this->isStateless($provider)
            ? $this->buildContinuationBody($provider, $model, $stepContext->continuationToken, $messages, $tools, $schema, $options)
            : $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }
}
