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
use Laravel\Ai\Gateway\Concerns\ResolvesInlineImages;
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
    use ResolvesInlineImages;

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
     * Append the images to the state as data URL image parts, labelling those attached under a name.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<int|string, File|UploadedFile>  $attachments
     *
     * @throws InvalidArgumentException
     */
    protected function stateWithImages(string|array $state, array $attachments): array
    {
        $images = $this->labeledImageParts($attachments, fn (string $label): string => $label, $this->mapImage(...));

        return is_array($state) && array_is_list($state) ? [...$state, ...$images] : [$state, ...$images];
    }

    /**
     * Map an image to a data URL image part.
     *
     * @throws InvalidArgumentException if the attachment is not a JPEG, PNG, or WebP image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment)
            ?? throw new InvalidArgumentException('OpenRouter decisions only accept images with inline content; ['.get_debug_type($attachment).'] given.');

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException("OpenRouter decisions only accept JPEG, PNG, or WebP images; [{$mime}] given.");
        }

        return array_merge($image->providerOptions(Lab::OpenRouter), [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($content)],
        ]);
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
