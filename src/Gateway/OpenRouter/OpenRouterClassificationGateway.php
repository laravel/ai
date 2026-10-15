<?php

namespace Laravel\Ai\Gateway\OpenRouter;

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
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ClassificationResponse;

class OpenRouterClassificationGateway implements ClassificationGateway
{
    use AnswersQuestions {
        classify as answerQuestions;
    }
    use Concerns\CreatesOpenRouterClient {
        baseUrl as openRouterBaseUrl;
    }
    use HandlesFailoverErrors;

    /**
     * Answer the given questions about the state, sending image attachments as image parts of the state.
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
        return $this->answerQuestions(
            $provider,
            $model,
            $attachments === [] ? $state : $this->stateWithImages($state, $attachments),
            $questions,
            $timeout,
            $providerOptions,
        );
    }

    /**
     * Append the images to the state as data URL image parts.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<int|string, File|UploadedFile>  $attachments
     *
     * @throws InvalidArgumentException
     */
    protected function stateWithImages(string|array $state, array $attachments): array
    {
        $images = array_map(function (File|UploadedFile $attachment): array {
            [$image, $content, $mime] = $this->systemOneImage($attachment);

            return array_merge($image->providerOptions(Lab::OpenRouter), [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($content)],
            ]);
        }, array_values($attachments));

        return is_array($state) && array_is_list($state) ? [...$state, ...$images] : [$state, ...$images];
    }

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/alpha/decisions';
    }

    /**
     * Get the base URL for the Decisions API, which is served outside of the versioned API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return Str::chopEnd($this->openRouterBaseUrl($provider), '/v1');
    }
}
