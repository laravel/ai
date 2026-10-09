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
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
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

    private const BYTES_PER_MIB = 1024 * 1024;

    private const MAX_ATTACHMENTS = [
        'images' => 4,
        'audio' => 4,
        'videos' => 2,
    ];

    private const MAX_ATTACHMENT_BYTES = [
        'images' => 4 * self::BYTES_PER_MIB,
        'audio' => 8 * self::BYTES_PER_MIB,
        'videos' => 16 * self::BYTES_PER_MIB,
    ];

    private const MAX_TOTAL_IMAGE_BYTES = 8 * self::BYTES_PER_MIB;

    private const MAX_TOTAL_AUDIO_VIDEO_BYTES = 16 * self::BYTES_PER_MIB;

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
            '@cf/cloudflare/clef-omni' => 'clef-omni',
            default => throw new InvalidArgumentException("Unsupported Cloudflare classification model [{$model}]."),
        };

        $payload = array_merge($providerOptions, [
            'model' => $selector,
            ...$this->mapAttachments($attachments, $model),
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
     * Group attachments by modality and map them to embedded data URLs.
     *
     * @param  array<int, File|UploadedFile>  $attachments
     * @return array<string, list<string>>
     */
    protected function mapAttachments(array $attachments, string $model): array
    {
        $groups = ['images' => [], 'audio' => [], 'videos' => []];

        foreach ($attachments as $attachment) {
            if ($attachment instanceof UploadedFile) {
                $mime = $attachment->getMimeType() ?? $attachment->getClientMimeType();

                $attachment = match (true) {
                    str_starts_with($mime, 'audio/') => Base64Audio::fromUpload($attachment, $mime),
                    str_starts_with($mime, 'video/') => Video::fromUpload($attachment, $mime),
                    default => Image::fromUpload($attachment, $mime),
                };
            }

            if (($attachment instanceof Audio || $attachment instanceof Video) && $model !== '@cf/cloudflare/clef-omni') {
                throw new InvalidArgumentException('Cloudflare audio and video classification requires the Clef Omni model.');
            }

            if (! $attachment instanceof StorableFile || (! $attachment instanceof Image && ! $attachment instanceof Audio && ! $attachment instanceof Video)) {
                $types = $model === '@cf/cloudflare/clef-omni' ? 'images, audio, or videos' : 'images';

                throw new InvalidArgumentException('Cloudflare Clef only accepts '.$types.' with inline content; ['.get_debug_type($attachment).'] given.');
            }

            $type = match (true) {
                $attachment instanceof Image => 'images',
                $attachment instanceof Audio => 'audio',
                default => 'videos',
            };

            $groups[$type][] = $attachment;
        }

        foreach (['images' => 'image', 'audio' => 'audio', 'videos' => 'video'] as $type => $label) {
            $limit = self::MAX_ATTACHMENTS[$type];

            if (count($groups[$type]) > $limit) {
                throw new InvalidArgumentException("Cloudflare Clef accepts a maximum of {$limit} {$label} attachments.");
            }
        }

        $mapped = [];
        $sizes = ['images' => 0, 'audio' => 0, 'videos' => 0];

        foreach ($groups as $type => $files) {
            foreach ($files as $file) {
                $content = $file->content();
                $sizes[$type] += strlen($content);

                if ($sizes['images'] > self::MAX_TOTAL_IMAGE_BYTES) {
                    $maxMiB = self::MAX_TOTAL_IMAGE_BYTES / self::BYTES_PER_MIB;

                    throw new InvalidArgumentException("Cloudflare Clef image attachments may not exceed {$maxMiB} MiB in total.");
                }

                if ($sizes['audio'] + $sizes['videos'] > self::MAX_TOTAL_AUDIO_VIDEO_BYTES) {
                    $maxMiB = self::MAX_TOTAL_AUDIO_VIDEO_BYTES / self::BYTES_PER_MIB;

                    throw new InvalidArgumentException("Cloudflare Clef audio and video attachments may not exceed {$maxMiB} MiB in total.");
                }

                $mapped[$type][] = $this->mapAttachment($file, $content, $type);
            }
        }

        return $mapped;
    }

    /**
     * Validate an attachment and encode its content as a data URL.
     */
    protected function mapAttachment(File $file, string $content, string $type): string
    {
        $mime = $file->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        $mime = match ($mime) {
            'image/jpg' => 'image/jpeg',
            'audio/mp3' => 'audio/mpeg',
            'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'audio/wav',
            default => $mime,
        };

        [$mimes, $formats] = match ($type) {
            'images' => [['image/jpeg', 'image/png', 'image/webp'], 'JPEG, PNG, or WebP images'],
            'audio' => [['audio/wav', 'audio/mpeg'], 'WAV or MP3 audio'],
            'videos' => [['video/mp4', 'video/webm'], 'MP4 or WebM videos'],
        };

        if (! in_array($mime, $mimes, true)) {
            throw new InvalidArgumentException("Cloudflare Clef only accepts {$formats}; [{$mime}] given.");
        }

        if (strlen($content) > self::MAX_ATTACHMENT_BYTES[$type]) {
            $maxMiB = self::MAX_ATTACHMENT_BYTES[$type] / self::BYTES_PER_MIB;

            throw new InvalidArgumentException("Cloudflare Clef {$type} attachments may not exceed {$maxMiB} MiB each.");
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
