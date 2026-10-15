<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use Laravel\Ai\Gateway\Concerns\AnswersQuestions;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ResolvesInlineImages;
use Laravel\Ai\Responses\ClassificationResponse;

class TypeSafeGateway implements ClassificationGateway
{
    use AnswersQuestions {
        classify as answerQuestions;
    }
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;
    use ResolvesInlineImages;

    /**
     * Answer the given questions about the state, sending image attachments as referenced assets.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, File|UploadedFile>  $attachments
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
        if ($attachments === []) {
            return $this->answerQuestions($provider, $model, $state, $questions, $timeout, $providerOptions);
        }

        $references = array_map(fn (): string => (string) Str::uuid(), $attachments);

        return $this->answerQuestions(
            $provider->withHeaders(['TypeSafe-Danger-Zone-Images' => 'true']),
            $model,
            $this->stateWithImages($state, $references),
            $questions,
            $timeout,
            [...$providerOptions, 'assets' => array_combine($references, array_map($this->mapImage(...), $attachments))],
        );
    }

    /**
     * Place the image references in the state: keyed by name into a structured state, or labelled by name in a list.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<int|string, string>  $references
     *
     * @throws InvalidArgumentException if a structured state already uses a key the images need.
     */
    protected function stateWithImages(string|array $state, array $references): array
    {
        if (is_string($state) || array_is_list($state)) {
            $labeled = array_map(fn (int|string $name, string $reference): string => is_string($name) ? "{$name}: {$reference}" : $reference, array_keys($references), $references);

            return [...(is_string($state) ? [$state] : $state), ...$labeled];
        }

        $images = array_filter($references, is_string(...), ARRAY_FILTER_USE_KEY);

        if ($unnamed = array_values(array_filter($references, is_int(...), ARRAY_FILTER_USE_KEY))) {
            $images['images'] = $unnamed;
        }

        if ($duplicates = array_intersect_key($state, $images)) {
            throw new InvalidArgumentException('TypeSafe adds images to a structured state under their attachment names, or [images] when unnamed; ['.implode(', ', array_keys($duplicates)).'] already in the state.');
        }

        return [...$state, ...$images];
    }

    /**
     * Map an image to a base64 System One asset.
     *
     * @throws InvalidArgumentException if the attachment is not a JPEG or PNG image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'TypeSafe decisions', [
            'image/jpeg' => 'JPEG', 'image/png' => 'PNG',
        ]);

        return array_merge($image->providerOptions(Lab::TypeSafe), [
            'type' => 'image',
            'format' => $mime === 'image/png' ? 'png' : 'jpg',
            'data_base64' => base64_encode($content),
        ]);
    }

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/systemone';
    }

    /**
     * Get the answering model, preferring the checkpoint a routing server such as laya-serve chose.
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['routing']['model'] ?? $data['model'] ?? $model;
    }

    /**
     * Get an HTTP client for the TypeSafe API.
     */
    protected function client(ClassificationProvider $provider, int $timeout = 30): PendingRequest
    {
        return $this->createClient(
            rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.typesafe.ai/v1', '/'),
            [
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function overloadedStatusCodes(): array
    {
        return [529, 502, 503, 504, 520, 522, 524];
    }
}
