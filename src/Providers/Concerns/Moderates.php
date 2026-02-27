<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\Moderated;
use Laravel\Ai\Events\Moderating;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\ModerationResponse;

trait Moderates
{
    /**
     * Check the given input for content that may violate usage policies.
     */
    public function moderate(string $input, ?string $model = null): ModerationResponse
    {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultModerationModel();

        $prompt = new ModerationPrompt($input, $this, $model);

        if (Ai::moderationIsFaked()) {
            Ai::recordModeration($prompt);
        }

        $this->events->dispatch(new Moderating(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->moderationGateway()->moderate(
            $this,
            $model,
            $input
        ), fn (ModerationResponse $response) => $this->events->dispatch(new Moderated(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }
}
