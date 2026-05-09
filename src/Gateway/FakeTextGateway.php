<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;
use function Laravel\Ai\ulid;

class FakeTextGateway implements TextGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayPrompts = false;

    public function __construct(
        protected Closure|array $responses,
    ) {}

    /**
     * Generate text representing the next message in a conversation.
     *
     * @param  array<string, Type>|null  $schema
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): SingleTurnResponse {
        $message = (new Collection($messages))->last(function ($message) {
            return $message instanceof UserMessage;
        });

        $response = $this->nextResponse(
            $provider, $model, $message->content, $message->attachments, $schema
        );

        if ($response instanceof ToolCall) {
            return new SingleTurnResponse(
                '',
                [$response],
                FinishReason::ToolCalls,
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        if ($response instanceof StructuredTextResponse) {
            return new SingleTurnResponse(
                $response->text,
                [],
                FinishReason::Stop,
                $response->usage,
                $response->meta,
                structured: $response->structured,
            );
        }

        if ($response instanceof TextResponse) {
            return new SingleTurnResponse(
                $response->text,
                [],
                FinishReason::Stop,
                $response->usage,
                $response->meta,
            );
        }

        return new SingleTurnResponse(
            (string) $response,
            [],
            FinishReason::Stop,
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Stream text representing the next message in a conversation.
     *
     * @param  array<string, Type>|null  $schema
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): Generator {
        $messageId = ulid();

        // Fake the stream and text starting...
        yield new StreamStart(ulid(), $provider->name(), $model, time());
        yield new TextStart(ulid(), $messageId, time());

        $message = (new Collection($messages))->last(function ($message) {
            return $message instanceof UserMessage;
        });

        $fakeResponse = $this->nextResponse(
            $provider, $model, $message->content, $message->attachments, $schema
        );

        $text = $fakeResponse instanceof TextResponse ? $fakeResponse->text : (string) $fakeResponse;

        $events = Str::of($text)
            ->explode(' ')
            ->map(fn ($word, $index) => new TextDelta(
                ulid(),
                $messageId,
                $index > 0 ? ' '.$word : $word,
                time(),
            ))->all();

        // Fake the text delta events...
        foreach ($events as $event) {
            yield $event;
        }

        // Fake the stream and text ending...
        yield new TextEnd(ulid(), $messageId, time());
        yield new StreamEnd(ulid(), 'stop', new Usage, time());
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
        ), fn () => $this->currentResponseIndex++);
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
