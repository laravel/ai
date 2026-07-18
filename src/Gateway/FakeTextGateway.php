<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use RuntimeException;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;
use function Laravel\Ai\ulid;

class FakeTextGateway implements StepTextGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayPrompts = false;

    public function __construct(
        protected Closure|array $responses,
    ) {}

    /**
     * Generate text for a single step in a conversation.
     *
     * @param  array<string, Type>|null  $schema
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
        return $this->nextStep($provider, $model, $messages, $schema);
    }

    /**
     * Stream text for a single step in a conversation.
     *
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, mixed, mixed, StepResponse>
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
        $step = $this->nextStep($provider, $model, $messages, $schema);

        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (filled($step->text)) {
            yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);

            foreach (Str::of($step->text)->explode(' ') as $index => $word) {
                yield (new TextDelta(
                    ulid(),
                    $messageId,
                    $index > 0 ? ' '.$word : $word,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($step->toolCalls as $toolCall) {
            yield (new ToolCallEvent(ulid(), $toolCall, time()))->withInvocationId($invocationId);
        }

        return $step;
    }

    /**
     * Resolve the next fake response and marshal it into a step response.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $message = (new Collection($messages))->last(fn ($message): bool => $message instanceof UserMessage);

        $prompt = $message instanceof UserMessage ? $message->content : '';
        $attachments = $message instanceof UserMessage ? $message->attachments : new Collection;

        $response = $this->nextResponse(
            $provider, $model, $prompt, $attachments, $schema
        );

        return $this->toStepResponse($response, $provider, $model);
    }

    /**
     * Convert a marshalled fake response into a step response for the generation loop.
     */
    protected function toStepResponse(mixed $response, TextProvider $provider, string $model): StepResponse
    {
        if ($response instanceof ToolCall) {
            return new StepResponse(
                '', [$response], FinishReason::ToolCalls, new Usage, new Meta($provider->name(), $model)
            );
        }

        if ($response instanceof StructuredTextResponse) {
            return new StepResponse(
                $response->text, [], FinishReason::Stop, $response->usage, $response->meta, $response->structured
            );
        }

        if ($response instanceof TextResponse && $response->awaitingApproval()) {
            return new StepResponse(
                $response->text, [], FinishReason::Stop, $response->usage, $response->meta,
                pendingApprovals: $response->pendingApprovals->all(),
            );
        }

        return new StepResponse(
            $response->text, [], FinishReason::Stop, $response->usage, $response->meta
        );
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(TextProvider $provider, string $model, string $prompt, Collection $attachments, ?array $schema): mixed
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt, $attachments, $provider, $model);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt, $attachments, $schema
        ), fn (): int => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        TextProvider $provider,
        string $model,
        string $prompt,
        Collection $attachments,
        ?array $schema): mixed
    {
        if (is_null($response)) {
            if ($this->preventStrayPrompts) {
                throw new RuntimeException('Attempted prompt ['.Str::words($prompt, 10).'] without a fake agent response.');
            }

            $response = is_null($schema)
                ? 'Fake response for prompt: '.Str::words($prompt, 10)
                : generate_fake_data_for_json_schema_type(new ObjectType($schema));
        }

        return match (true) {
            is_string($response) => new TextResponse(
                $response, new Usage, new Meta($provider->name(), $model)
            ),
            is_array($response) => new StructuredTextResponse(
                $response, json_encode($response), new Usage, new Meta($provider->name(), $model)
            ),
            $response instanceof Closure => $this->marshalResponse(
                $response($prompt, $attachments, $provider, $model),
                $provider,
                $model,
                $prompt,
                $attachments,
                $schema
            ),
            default => $response,
        };
    }

    /**
     * Indicate that an exception should be thrown if any prompt is not faked.
     */
    public function preventStrayPrompts(bool $prevent = true): self
    {
        $this->preventStrayPrompts = $prevent;

        return $this;
    }
}
