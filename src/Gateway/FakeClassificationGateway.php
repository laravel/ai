<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;
use RuntimeException;

class FakeClassificationGateway implements ClassificationGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayClassifications = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

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
        $prompt = new ClassificationPrompt($state, $questions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt
    ): ClassificationResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt
        ), fn (): int => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt
    ): ClassificationResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayClassifications) {
                throw new RuntimeException('Attempted classification without a fake response.');
            }

            $response = [];
        }

        if ($response instanceof ClassificationResponse) {
            return $response;
        }

        $answers = array_map(
            fn (Question $question, string $key) => $response[$key] ?? $this->generateFakeAnswer($question),
            $prompt->questions,
            array_keys($prompt->questions),
        );

        return new ClassificationResponse(
            array_combine(array_keys($prompt->questions), $answers),
            new TextUsage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate a shape-valid fake answer for the given question.
     */
    protected function generateFakeAnswer(Question $question): Answer
    {
        return match (true) {
            $question instanceof Boolean => new BooleanAnswer(round(mt_rand() / mt_getrandmax(), 3)),
            $question instanceof Choice => $this->fakeChoiceAnswer(array_keys($question->options)),
            $question instanceof Score => $this->fakeScoreAnswer($question->levels),
        };
    }

    /**
     * @param  list<string>  $options
     */
    protected function fakeChoiceAnswer(array $options): ChoiceAnswer
    {
        $probabilities = $this->randomDistribution($options);

        return new ChoiceAnswer(
            array_search(max($probabilities), $probabilities, true),
            $probabilities,
            round(max($probabilities), 3),
        );
    }

    /**
     * @param  list<string|array<string, mixed>>  $levels
     */
    protected function fakeScoreAnswer(array $levels): ScoreAnswer
    {
        $probabilities = $this->randomDistribution(array_keys($levels));

        $score = array_sum(array_map(fn (int $level, float $p) => $level * $p, array_keys($probabilities), $probabilities));

        return new ScoreAnswer(round($score, 3), $probabilities, $levels, round(max($probabilities), 3));
    }

    /**
     * Generate random probabilities summing to one over the given keys.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, float>
     */
    protected function randomDistribution(array $keys): array
    {
        $weights = array_map(fn () => mt_rand(1, 100), $keys);

        $total = array_sum($weights);

        return array_combine($keys, array_map(fn (int $weight) => round($weight / $total, 3), $weights));
    }

    /**
     * Indicate that an exception should be thrown if any classification is not faked.
     */
    public function preventStrayClassifications(bool $prevent = true): self
    {
        $this->preventStrayClassifications = $prevent;

        return $this;
    }
}
