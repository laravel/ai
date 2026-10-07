<?php

namespace Laravel\Ai\Contracts\Providers;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\ClassificationResponse;

interface ClassificationProvider extends Provider
{
    /**
     * Answer the given questions about the state.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, File|UploadedFile>  $attachments
     */
    public function classify(string|array $state, array $questions, ?string $model = null, int $timeout = 30, array $providerOptions = [], array $attachments = []): ClassificationResponse;

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway;

    /**
     * Set the provider's classification gateway.
     */
    public function useClassificationGateway(ClassificationGateway $gateway): self;

    /**
     * Get the name of the default classification model.
     */
    public function defaultClassificationModel(): string;
}
