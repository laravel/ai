<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
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
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
    ): ClassificationResponse {
        $selector = match ($model) {
            '@cf/cloudflare/clef' => 'clef',
            '@cf/cloudflare/clef-flash' => 'clef-flash',
            default => throw new InvalidArgumentException("Unsupported Cloudflare classification model [{$model}]."),
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post($this->classificationEndpoint().'/'.$model, array_merge($providerOptions, [
                'model' => $selector,
                'state' => $state,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ])),
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
