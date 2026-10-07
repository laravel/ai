<?php

namespace Laravel\Ai\Gateway;

use finfo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use UnexpectedValueException;

class CloudflareClassificationGateway implements ClassificationGateway
{
    use Concerns\AnswersQuestions;
    use Concerns\CreatesClient;
    use Concerns\HandlesFailoverErrors;

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/ai/run';
    }

    /**
     * Answer the given questions using the Workers AI model identifier and its input selector.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, File|UploadedFile>  $attachments
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        $selector = match ($model) {
            '@cf/cloudflare/clef' => 'clef',
            '@cf/cloudflare/clef-flash' => 'clef-flash',
            default => throw new InvalidArgumentException("Unsupported Cloudflare classification model [{$model}]."),
        };

        if (count($attachments) > 4) {
            throw new InvalidArgumentException('Cloudflare Clef accepts a maximum of 4 image attachments.');
        }

        $payload = array_merge($providerOptions, [
            'model' => $selector,
            ...($attachments === [] ? [] : ['images' => array_map($this->mapImage(...), array_values($attachments))]),
            'state' => $state,
            'questions' => array_map($this->mapQuestion(...), $questions),
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post($this->classificationEndpoint().'/'.$model, $payload),
        );

        $data = $this->classificationResponseData($response);

        $answers = [];

        foreach ($data['answers'] as $key => $answer) {
            if (($mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) instanceof Answer) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $this->answeringModel($data, $model)),
        );
    }

    /**
     * Unwrap the Workers AI REST response, rejecting unsuccessful or malformed responses.
     */
    protected function classificationResponseData(Response $response): array
    {
        $data = $response->json();

        if (($data['success'] ?? null) === false || ! empty($data['errors'])) {
            throw new RequestException($response);
        }

        if (($data['success'] ?? null) !== true || ! is_array($data['result']['answers'] ?? null) || $data['result']['answers'] === []) {
            throw new UnexpectedValueException('Cloudflare returned an invalid classification response.');
        }

        return $data['result'];
    }

    /**
     * Map an image to a data URL accepted by Cloudflare Clef.
     *
     * @throws InvalidArgumentException if the attachment is not a JPEG, PNG, or WebP image with inline content.
     */
    protected function mapImage(File|UploadedFile $image): string
    {
        if ($image instanceof UploadedFile) {
            $image = Image::fromUpload($image);
        }

        if (! $image instanceof Image || ! $image instanceof StorableFile) {
            throw new InvalidArgumentException('Cloudflare Clef only accepts images with inline content; ['.get_debug_type($image).'] given.');
        }

        $content = $image->content();

        $mime = $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException("Cloudflare Clef only accepts JPEG, PNG, or WebP images; [{$mime}] given.");
        }

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    /**
     * Get an HTTP client for the Workers AI API.
     */
    protected function client(ClassificationProvider $provider, int $timeout = 30): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        if (blank($config['account_id'] ?? null)) {
            throw new InvalidArgumentException('A Cloudflare account ID is required to classify.');
        }

        return $this->createClient(
            rtrim($config['url'] ?? 'https://api.cloudflare.com/client/v4', '/').'/accounts/'.$config['account_id'],
            [
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $config['headers'] ?? [],
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function overloadedStatusCodes(): array
    {
        return [408, 500, 502, 503, 504, 520, 521, 522, 523, 524];
    }
}
