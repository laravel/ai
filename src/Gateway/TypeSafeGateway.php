<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\Usage;

class TypeSafeGateway implements ClassificationGateway
{
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;

    /**
     * Answer the given questions about the state.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
    ): ClassificationResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/systemone', array_merge($providerOptions, [
                'model' => $model,
                'state' => $state,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ])),
        );

        $data = $response->json();

        $answers = [];

        foreach ($data['answers'] as $key => $answer) {
            if ($mapped = $this->mapAnswer($answer)) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new Usage(
                promptTokens: $data['usage']['input_tokens'] ?? 0,
                completionTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $data['model'] ?? $model),
        );
    }

    /**
     * Map a question to the System One wire format.
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], fn ($value) => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $question->instructions,
                'criteria' => $question->options,
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $question->instructions,
                'criteria' => $question->levels,
            ],
            default => $question->toArray(),
        };
    }

    /**
     * Map a System One answer to an answer object, skipping unknown answer types.
     */
    protected function mapAnswer(array $answer): ?Answer
    {
        return match ($answer['type'] ?? null) {
            'noul' => new BooleanAnswer($answer['noul']),
            'choice' => new ChoiceAnswer($answer['choice'], $answer['probabilities'], $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $this->withIntegerKeys($answer['probabilities']),
                $this->withIntegerKeys($answer['legend']),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Cast the string level keys System One returns to integers.
     */
    protected function withIntegerKeys(array $levels): array
    {
        $result = [];

        foreach ($levels as $level => $value) {
            $result[(int) $level] = $value;
        }

        ksort($result);

        return $result;
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
