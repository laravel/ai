<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

class ReplicateGateway implements ImageGateway, TextGateway
{
    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    public function __construct()
    {
        $this->invokingToolCallback = fn () => true;
        $this->toolInvokedCallback = fn () => true;
    }

    /**
     * {@inheritdoc}
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
    ): TextResponse {
        if (! empty($tools)) {
            throw new InvalidArgumentException('Replicate provider does not support tools yet.');
        }

        if (! empty($schema)) {
            throw new InvalidArgumentException('Replicate provider does not support structured output yet.');
        }

        $input = $this->formatInput($instructions, $messages);

        $prediction = $this->createPrediction(
            $provider,
            $model,
            $input,
            sync: true,
            timeout: $timeout ?? 120
        );

        if ($prediction['status'] === 'failed') {
            throw new \RuntimeException(
                'Replicate prediction failed: '.($prediction['error'] ?? 'Unknown error')
            );
        }

        $output = $this->normalizeOutput($prediction['output']);

        $usage = new Usage(
            promptTokens: 0,
            completionTokens: 0,
        );

        return new TextResponse(
            $output,
            $usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * {@inheritdoc}
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
    ): Generator {
        if (! empty($tools)) {
            throw new InvalidArgumentException('Replicate provider does not support tools yet.');
        }

        if (! empty($schema)) {
            throw new InvalidArgumentException('Replicate provider does not support structured output yet.');
        }

        $input = $this->formatInput($instructions, $messages);

        $prediction = $this->createPrediction(
            $provider,
            $model,
            $input,
            sync: false,
            timeout: $timeout ?? 120
        );

        if (! isset($prediction['urls']['stream'])) {
            throw new \RuntimeException('Replicate prediction does not support streaming.');
        }

        $messageId = (string) Str::uuid7();
        $timestamp = now()->timestamp;

        yield (new StreamStart($messageId, $timestamp))->withInvocationId($invocationId);

        yield from $this->streamFromUrl(
            $invocationId,
            $messageId,
            $prediction['urls']['stream'],
            $provider,
            $model
        );

        yield (new StreamEnd($messageId, $timestamp))->withInvocationId($invocationId);
    }

    /**
     * Stream events from the Replicate SSE stream URL.
     */
    protected function streamFromUrl(
        string $invocationId,
        string $messageId,
        string $streamUrl,
        TextProvider $provider,
        string $model
    ): Generator {
        $response = Http::withoutRedirecting()
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->timeout(300)
            ->get($streamUrl);

        $stream = $response->getBody();
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $eventData = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if ($parsed = $this->parseSSE($eventData)) {
                    if ($parsed['type'] === 'output' && isset($parsed['data'])) {
                        $delta = is_string($parsed['data']) ? $parsed['data'] : '';

                        if ($delta !== '') {
                            yield (new TextDelta(
                                (string) Str::uuid7(),
                                $messageId,
                                $delta,
                                now()->timestamp
                            ))->withInvocationId($invocationId);
                        }
                    } elseif ($parsed['type'] === 'done') {
                        break 2;
                    } elseif ($parsed['type'] === 'error') {
                        throw new \RuntimeException(
                            'Replicate stream error: '.json_encode($parsed['data'])
                        );
                    }
                }
            }
        }
    }

    /**
     * Parse Server-Sent Events format.
     */
    protected function parseSSE(string $event): ?array
    {
        $lines = explode("\n", $event);
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'event: ')) {
                $data['type'] = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'data: ')) {
                $jsonData = trim(substr($line, 6));
                $data['data'] = json_decode($jsonData, true) ?? $jsonData;
            }
        }

        return empty($data) ? null : $data;
    }

    /**
     * Create a prediction on Replicate.
     */
    protected function createPrediction(
        TextProvider|ImageProvider $provider,
        string $model,
        array $input,
        bool $sync = true,
        int $timeout = 120
    ): array {
        $client = $this->client($provider)->timeout($timeout);

        if ($sync) {
            $client = $client->withHeaders(['Prefer' => 'wait']);
        }

        $payload = ['input' => $input];

        if (str_contains($model, ':')) {
            $payload['version'] = explode(':', $model, 2)[1];
        } else {
            $payload['model'] = $model;
        }

        $response = $client->post('/predictions', $payload);

        return $response->json();
    }

    /**
     * Format input for Replicate model.
     */
    protected function formatInput(?string $instructions, array $messages): array
    {
        $prompt = '';

        if ($instructions) {
            $prompt .= $instructions."\n\n";
        }

        foreach ($messages as $message) {
            if (method_exists($message, 'content')) {
                $content = $message->content();

                if (method_exists($message, 'role')) {
                    $role = $message->role();
                    $prompt .= ucfirst($role).': '.$content."\n";
                } else {
                    $prompt .= $content."\n";
                }
            }
        }

        return ['prompt' => trim($prompt)];
    }

    /**
     * Normalize Replicate output to a string.
     *
     * Replicate models can return output as:
     * - string
     * - array of strings
     * - null
     */
    protected function normalizeOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            return implode('', array_filter($output, 'is_string'));
        }

        return '';
    }

    /**
     * Get an HTTP client for the Replicate API.
     */
    protected function client(TextProvider|ImageProvider $provider): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::baseUrl($config['url'] ?? 'https://api.replicate.com/v1')
            ->withHeaders([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ])
            ->throw();
    }

    /**
     * {@inheritdoc}
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        if (! empty($attachments)) {
            throw new InvalidArgumentException('Replicate provider does not support image attachments yet.');
        }

        $input = array_merge(
            ['prompt' => $prompt],
            $provider->defaultImageOptions($size, $quality)
        );

        $prediction = $this->createPrediction(
            $provider,
            $model,
            $input,
            sync: true,
            timeout: $timeout ?? 120
        );

        if ($prediction['status'] === 'failed') {
            throw new \RuntimeException(
                'Replicate prediction failed: '.($prediction['error'] ?? 'Unknown error')
            );
        }

        $images = $this->normalizeImageOutput($prediction['output']);

        $usage = new Usage(
            promptTokens: 0,
            completionTokens: 0,
        );

        return new ImageResponse(
            (new Collection($images))->map(function ($imageUrl) {
                return new GeneratedImage(
                    $this->fetchImageAsBase64($imageUrl),
                    $this->detectMimeType($imageUrl)
                );
            }),
            $usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Normalize Replicate image output to an array of URLs.
     */
    protected function normalizeImageOutput(mixed $output): array
    {
        if (is_string($output)) {
            return [$output];
        }

        if (is_array($output)) {
            return array_filter($output, 'is_string');
        }

        return [];
    }

    /**
     * Fetch image from URL and convert to base64.
     */
    protected function fetchImageAsBase64(string $url): string
    {
        $response = Http::timeout(30)->get($url);

        return base64_encode($response->body());
    }

    /**
     * Detect MIME type from image URL or content.
     */
    protected function detectMimeType(string $url): string
    {
        if (str_ends_with($url, '.png')) {
            return 'image/png';
        }

        if (str_ends_with($url, '.jpg') || str_ends_with($url, '.jpeg')) {
            return 'image/jpeg';
        }

        if (str_ends_with($url, '.webp')) {
            return 'image/webp';
        }

        return 'image/png';
    }

    /**
     * {@inheritdoc}
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }
}
