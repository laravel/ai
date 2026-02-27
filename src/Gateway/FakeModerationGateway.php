<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ModerationCategory;
use Laravel\Ai\Responses\ModerationResponse;
use RuntimeException;

class FakeModerationGateway implements ModerationGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayModerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Check the given input for content that may violate usage policies.
     */
    public function moderate(
        ModerationProvider $provider,
        string $model,
        string $input
    ): ModerationResponse {
        $prompt = new ModerationPrompt($input, $provider, $model);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        ModerationProvider $provider,
        string $model,
        ModerationPrompt $prompt
    ): ModerationResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        ModerationProvider $provider,
        string $model,
        ModerationPrompt $prompt
    ): ModerationResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayModerations) {
                throw new RuntimeException('Attempted moderation without a fake response.');
            }

            $response = $this->generateFakeModeration();
        }

        if ($response instanceof ModerationResponse) {
            return $response;
        }

        if (is_array($response) && isset($response[0]) && $response[0] instanceof ModerationCategory) {
            $flagged = array_any($response, fn (ModerationCategory $category) => $category->flagged);

            return new ModerationResponse(
                $flagged,
                $response,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate a fake moderation response.
     *
     * @return array<int, ModerationCategory>
     */
    protected function generateFakeModeration(): array
    {
        return [
            new ModerationCategory('hate', false, 0.0001),
            new ModerationCategory('hate/threatening', false, 0.0001),
            new ModerationCategory('harassment', false, 0.0001),
            new ModerationCategory('harassment/threatening', false, 0.0001),
            new ModerationCategory('illicit', false, 0.0001),
            new ModerationCategory('illicit/violent', false, 0.0001),
            new ModerationCategory('self-harm', false, 0.0001),
            new ModerationCategory('self-harm/intent', false, 0.0001),
            new ModerationCategory('self-harm/instructions', false, 0.0001),
            new ModerationCategory('sexual', false, 0.0001),
            new ModerationCategory('sexual/minors', false, 0.0001),
            new ModerationCategory('violence', false, 0.0001),
            new ModerationCategory('violence/graphic', false, 0.0001),
        ];
    }

    /**
     * Indicate that an exception should be thrown if any moderation is not faked.
     */
    public function preventStrayModerations(bool $prevent = true): self
    {
        $this->preventStrayModerations = $prevent;

        return $this;
    }
}
