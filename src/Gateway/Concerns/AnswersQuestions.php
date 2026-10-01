<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\Client\Response;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;

trait AnswersQuestions
{
    /**
     * Get the path of the endpoint that answers questions.
     */
    abstract protected function classificationEndpoint(): string;

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
            fn () => $this->sendClassificationRequest($provider, array_merge($providerOptions, [
                'model' => $model,
                'state' => $state,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ]), $timeout),
        );

        $data = $this->classificationResponseData($response);

        $answers = [];

        foreach ($data['answers'] ?? [] as $key => $answer) {
            if ($mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) {
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
     * Send a classification request to the provider.
     */
    protected function sendClassificationRequest(ClassificationProvider $provider, array $payload, int $timeout): Response
    {
        return $this->client($provider, $timeout)->post($this->classificationEndpoint(), $payload);
    }

    /**
     * Get the classification data from the provider's response.
     */
    protected function classificationResponseData(Response $response): array
    {
        return $response->json();
    }

    /**
     * Get the name of the model that answered the questions.
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['model'] ?? $model;
    }

    /**
     * Map a question to the decisions wire format.
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], fn ($value): bool => $value !== null),
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
     * Map an answer to an answer object, skipping unknown answer types.
     */
    protected function mapAnswer(array $answer, ?Question $question = null): ?Answer
    {
        return match ($answer['type'] ?? null) {
            'noul' => new BooleanAnswer($answer['noul']),
            'choice' => new ChoiceAnswer($answer['choice'], $answer['probabilities'] ?? [], $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $this->withIntegerKeys($answer['probabilities'] ?? []),
                $this->withIntegerKeys($answer['legend'] ?? ($question instanceof Score ? $question->levels : [])),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Cast the string level keys the provider returns to integers.
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
}
