<?php

namespace Laravel\Ai\Gateway\Gemini;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use Laravel\Ai\Gateway\Concerns\AnswersQuestions;
use Laravel\Ai\Gateway\Concerns\ResolvesInlineImages;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

class GeminiClassificationGateway implements ClassificationGateway
{
    use AnswersQuestions;
    use Concerns\CreatesGeminiClient;
    use Concerns\HandlesGeminiErrors;
    use ResolvesInlineImages;

    /**
     * Answer the given questions about the state.
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
        $input = $this->mapInput($state, $attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post($this->classificationEndpoint(), array_merge($providerOptions, [
                'model' => $model,
                'input' => $input,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ])),
        );

        $data = $response->json();

        $answers = [];

        foreach ($data['answers'] ?? [] as $key => $answer) {
            if ($mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) {
                $answers[$key] = $mapped;
            }
        }

        return (new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['total_input_tokens'] ?? 0,
                outputTokens: $data['usage']['total_output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        ))->withRawResponse($response);
    }

    /**
     * Map the state and attachments to the decisions input.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    protected function mapInput(string|array $state, array $attachments): string|array
    {
        $text = $this->toText($state);

        if ($attachments === []) {
            return $text;
        }

        $content = [['type' => 'text', 'text' => $text]];

        foreach ($attachments as $name => $attachment) {
            if (is_string($name)) {
                $content[] = ['type' => 'text', 'text' => "{$name}:"];
            }

            $content[] = $this->mapImage($attachment);
        }

        return $content;
    }

    /**
     * Map an image to an inline image content block.
     *
     * @throws InvalidArgumentException if the attachment is not a supported image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'Gemini decisions', [
            'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF', 'image/webp' => 'WebP',
            'image/heic' => 'HEIC', 'image/heif' => 'HEIF', 'image/bmp' => 'BMP', 'image/tiff' => 'TIFF',
        ]);

        return array_merge($image->providerOptions(Lab::Gemini), [
            'type' => 'image',
            'data' => base64_encode($content),
            'mime_type' => $mime,
        ]);
    }

    /**
     * Map a question to the Gemini decisions wire format.
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $this->toText($question->instructions),
                'criteria' => $question->criteria === null ? null : array_filter([
                    'true_description' => isset($question->criteria['true']) ? $this->toText($question->criteria['true']) : null,
                    'false_description' => isset($question->criteria['false']) ? $this->toText($question->criteria['false']) : null,
                ], fn ($value) => $value !== null),
            ], fn ($value) => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $this->toText($question->instructions),
                'criteria' => array_map(fn ($description) => is_null($description) ? null : $this->toText($description), $question->options),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $this->toText($question->instructions),
                'criteria' => array_map($this->toText(...), $question->levels),
            ],
            default => $question->toArray(),
        };
    }

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return 'decisions';
    }

    /**
     * Convert structured content to the text the endpoint accepts.
     *
     * @param  string|array<string, mixed>  $value
     */
    protected function toText(string|array $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
