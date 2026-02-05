<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\GeneratingModeration;
use Laravel\Ai\Events\ModerationGenerated;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\ModerationResponse;

trait GeneratesModerations
{
    /**
     * Moderate the given input(s).
     *
     * @param  string|string[]  $input
     */
    public function moderate(string|array $input, ?string $model = null): ModerationResponse
    {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultModerationModel();

        $prompt = new ModerationPrompt($input, $this, $model);

        if (Ai::moderationsAreFaked()) {
            Ai::recordModerationGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingModeration(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->moderationGateway()->moderate(
            $this,
            $model,
            $input
        ), fn (ModerationResponse $response) => $this->events->dispatch(new ModerationGenerated(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }
}
