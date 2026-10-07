<?php

namespace Laravel\Ai\Contracts\Gateway;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\ClassificationResponse;

interface ClassificationGateway
{
    /**
     * Answer the given questions about the state.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, File|UploadedFile>  $attachments
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse;
}
