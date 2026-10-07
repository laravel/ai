<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Files\File;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use LogicException;

trait Classifies
{
    /**
     * Answer the given questions about the state.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, File|UploadedFile>  $attachments
     */
    public function classify(string|array $state, array $questions, ?string $model = null, int $timeout = 30, array $providerOptions = [], array $attachments = []): ClassificationResponse
    {
        if ($attachments !== [] && ! $this->supportsClassificationAttachments()) {
            throw new LogicException("Provider [{$this->name()}] does not support classification attachments.");
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultClassificationModel();

        $prompt = new ClassificationPrompt($state, $questions, $this, $model, $timeout, $providerOptions, $attachments);

        if (Ai::classificationIsFaked()) {
            Ai::recordClassification($prompt);
        }

        $this->events->dispatch(new Classifying(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->classificationGateway()->classify(
            $this,
            $model,
            $state,
            $questions,
            $timeout,
            $providerOptions,
            $attachments,
        ), fn (ClassificationResponse $response) => $this->events->dispatch(new Classified(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }

    /**
     * Determine if the provider can classify attachments alongside the state.
     */
    public function supportsClassificationAttachments(): bool
    {
        return false;
    }
}
