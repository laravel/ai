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
use Laravel\Ai\Contracts\Schemable;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\RawSchema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Schema;
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
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|Schemable|null  $schema
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        array|Schemable|null $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $message = (new Collection($messages))->last(function ($message) {
            return $message instanceof UserMessage;
        });

        return $this->nextResponse(
            $provider, $model, $message->content, $message->attachments, $schema
        );
    }

    /**
     * Stream text representing the next message in a conversation.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|Schemable|null  $schema
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        array|Schemable|null $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
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

        $events = Str::of($fakeResponse->text)
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
    protected function nextResponse(TextProvider $provider, string $model, string $prompt, Collection $attachments, array|Schemable|null $schema): mixed
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
        array|Schemable|null $schema): mixed
    {
        if (is_null($response)) {
            if ($this->preventStrayPrompts) {
                throw new RuntimeException('Attempted prompt ['.Str::words($prompt, 10).'] without a fake agent response.');
            }

            $response = is_null($schema)
                ? 'Fake response for prompt: '.Str::words($prompt, 10)
                : $this->generateFakeDataForSchema($schema);
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
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|Schemable  $schema
     */
    protected function generateFakeDataForSchema(array|Schemable $schema): mixed
    {
        if (is_array($schema)) {
            if ($this->isTypeMapSchema($schema)) {
                return generate_fake_data_for_json_schema_type(new ObjectType($schema));
            }

            return $this->generateFakeDataForRawSchema($schema);
        }

        if ($schema instanceof Schema && $schema->schema instanceof ObjectType) {
            return generate_fake_data_for_json_schema_type($schema->schema);
        }

        if ($schema instanceof RawSchema) {
            return $this->generateFakeDataForRawSchema($schema->definition());
        }

        return $this->generateFakeDataForRawSchema(
            $this->normalizeRawSchemaPayload($schema->toSchema())
        );
    }

    /**
     * Determine if the array contains Laravel JsonSchema type instances.
     */
    protected function isTypeMapSchema(array $schema): bool
    {
        return $schema === [] || collect($schema)->every(
            fn (mixed $value): bool => $value instanceof Type
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function normalizeRawSchemaPayload(array $schema): array
    {
        if (isset($schema['name']) && is_string($schema['name'])) {
            unset($schema['name']);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function generateFakeDataForRawSchema(array $schema): mixed
    {
        $type = $schema['type'] ?? 'object';

        if (is_array($type)) {
            $type = collect($type)->first(fn ($value) => is_string($value) && $value !== 'null') ?? 'string';
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && count($schema['enum']) > 0) {
            return $schema['enum'][array_rand($schema['enum'])];
        }

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        return match ($type) {
            'object' => (function () use ($schema) {
                $properties = is_array($schema['properties'] ?? null)
                    ? $schema['properties']
                    : [];
                $required = collect($schema['required'] ?? [])
                    ->filter(fn ($value) => is_string($value))
                    ->values()
                    ->all();
                $result = [];

                foreach ($properties as $name => $property) {
                    if (! is_string($name) || ! is_array($property)) {
                        continue;
                    }

                    if (! in_array($name, $required, true) && random_int(0, 1) === 0) {
                        continue;
                    }

                    $result[$name] = $this->generateFakeDataForRawSchema($property);
                }

                return $result;
            })(),
            'array' => (function () use ($schema) {
                $min = (int) ($schema['minItems'] ?? 1);
                $max = (int) ($schema['maxItems'] ?? max($min, 3));

                if ($max < $min) {
                    $max = $min;
                }

                $items = is_array($schema['items'] ?? null)
                    ? $schema['items']
                    : ['type' => 'string'];
                $count = random_int($min, $max);

                return collect(range(1, $count))
                    ->map(fn ($value) => $this->generateFakeDataForRawSchema($items))
                    ->all();
            })(),
            'integer' => (function () use ($schema) {
                $min = (int) ($schema['minimum'] ?? 0);
                $max = (int) ($schema['maximum'] ?? max($min, 100));

                if ($max < $min) {
                    $max = $min;
                }

                return random_int($min, $max);
            })(),
            'number' => (function () use ($schema) {
                $min = (float) ($schema['minimum'] ?? 0.0);
                $max = (float) ($schema['maximum'] ?? max($min, 100.0));

                if ($max < $min) {
                    $max = $min;
                }

                return $min + mt_rand() / mt_getrandmax() * ($max - $min);
            })(),
            'boolean' => random_int(0, 1) === 0,
            default => (function () use ($schema) {
                if (isset($schema['format']) && is_string($schema['format'])) {
                    return match ($schema['format']) {
                        'date' => date('Y-m-d'),
                        'date-time' => date('c'),
                        'email' => 'user@example.com',
                        'time' => date('H:i:s'),
                        'uri', 'url' => 'https://example.com',
                        'uuid' => (string) Str::uuid(),
                        default => 'string',
                    };
                }

                return 'string';
            })(),
        };
    }

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        return $this;
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
