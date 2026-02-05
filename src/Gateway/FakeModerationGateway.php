<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Moderation;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ModerationResponse;
use Laravel\Ai\Responses\ModerationResult;
use RuntimeException;

class FakeModerationGateway implements ModerationGateway
{
    protected array $callHistory = [];
    protected bool $shouldThrowOnUnfaked = false;

    public function __construct(
        protected Closure|array $fakeModerationResponses = [],
    ) {}

    /**
     * Moderate the given input(s).
     */
    public function moderate(
        ModerationProvider $provider,
        string $model,
        string|array $input
    ): ModerationResponse {
        $moderationPrompt = new ModerationPrompt($input, $provider, $model);
        
        return $this->buildResponse($provider, $model, $moderationPrompt);
    }

    /**
     * Build a moderation response from faked data or generate defaults.
     */
    protected function buildResponse(
        ModerationProvider $provider,
        string $model,
        ModerationPrompt $prompt
    ): ModerationResponse {
        $fakedData = $this->getFakedDataForPrompt($prompt);
        
        if ($fakedData instanceof Closure) {
            $fakedData = $fakedData($prompt);
        }

        if (is_null($fakedData) && $this->shouldThrowOnUnfaked) {
            throw new RuntimeException('Attempted moderation without a fake response configured.');
        }

        $finalResults = $fakedData ?? $this->createDefaultResults($prompt);

        if ($finalResults instanceof ModerationResponse) {
            return $finalResults;
        }

        $wrappedResults = $this->wrapResultsInObjects($finalResults);
        
        return new ModerationResponse(
            $wrappedResults,
            new Meta($provider->name(), $model)
        );
    }

    /**
     * Get faked data for the current prompt.
     */
    protected function getFakedDataForPrompt(ModerationPrompt $prompt): mixed
    {
        if ($this->fakeModerationResponses instanceof Closure) {
            return call_user_func($this->fakeModerationResponses, $prompt);
        }

        if (is_array($this->fakeModerationResponses)) {
            $historyCount = count($this->callHistory);
            $this->callHistory[] = $prompt;
            
            return $this->fakeModerationResponses[$historyCount] ?? null;
        }

        return null;
    }

    /**
     * Create default safe results when no fakes are provided.
     */
    protected function createDefaultResults(ModerationPrompt $prompt): array
    {
        $inputCount = is_string($prompt->input) ? 1 : count($prompt->input);
        
        return array_fill(0, $inputCount, [
            'flagged' => false,
            'categories' => $this->getDefaultCategories(false),
            'category_scores' => $this->getDefaultScores(),
        ]);
    }

    /**
     * Wrap raw result arrays in ModerationResult objects.
     */
    protected function wrapResultsInObjects(array $results): array
    {
        return array_map(function ($item) {
            if ($item instanceof ModerationResult) {
                return $item;
            }
            
            return new ModerationResult(
                $item['flagged'] ?? false,
                $item['categories'] ?? $this->getDefaultCategories(false),
                $item['category_scores'] ?? $this->getDefaultScores(),
            );
        }, $results);
    }

    /**
     * Get default category flags.
     */
    protected function getDefaultCategories(bool $allFlagged = false): array
    {
        $categoryNames = [
            'hate', 'hate/threatening', 'harassment', 'harassment/threatening',
            'self-harm', 'self-harm/intent', 'self-harm/instructions',
            'sexual', 'sexual/minors', 'violence', 'violence/graphic', 'illicit', 'illicit/violent'
        ];
        
        return array_combine($categoryNames, array_fill(0, count($categoryNames), $allFlagged));
    }

    /**
     * Get default category scores.
     */
    protected function getDefaultScores(): array
    {
        $categoryNames = [
            'hate', 'hate/threatening', 'harassment', 'harassment/threatening',
            'self-harm', 'self-harm/intent', 'self-harm/instructions',
            'sexual', 'sexual/minors', 'violence', 'violence/graphic', 'illicit', 'illicit/violent'
        ];
        
        return array_combine($categoryNames, array_fill(0, count($categoryNames), 0.001));
    }

    /**
     * Configure gateway to throw when unfaked moderations are attempted.
     */
    public function preventStrayModerations(bool $prevent = true): self
    {
        $this->shouldThrowOnUnfaked = $prevent;
        
        return $this;
    }
}
