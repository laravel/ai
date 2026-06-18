<?php

namespace Laravel\Ai\Pricing;

class PriceList
{
    /**
     * Pricing registered at runtime, keyed by provider then model.
     *
     * @var array<string, array<string, ModelPricing>>
     */
    protected array $registered = [];

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $overrides  Config-supplied rates, merged over the built-in defaults.
     */
    public function __construct(protected array $overrides = []) {}

    /**
     * Register (or override) pricing for a given provider and model.
     */
    public function register(string $provider, string $model, ModelPricing $pricing): self
    {
        $this->registered[$provider][$model] = $pricing;

        return $this;
    }

    /**
     * Resolve the pricing for the given provider and model, if known.
     *
     * Resolution order per provider: built-in defaults, then config('ai.pricing.models'),
     * then anything registered at runtime. Models are matched exactly, otherwise by the
     * longest key contained in the model id (so versioned ids like "claude-sonnet-4-6"
     * still resolve to "claude-sonnet-4").
     */
    public function for(?string $provider, ?string $model): ?ModelPricing
    {
        if ($provider === null || $model === null) {
            return null;
        }

        $table = $this->table($provider);

        if ($table === []) {
            return null;
        }

        if (isset($table[$model])) {
            return $this->normalize($table[$model]);
        }

        $bestKey = null;

        foreach ($table as $key => $rates) {
            if ($key !== '' && str_contains($model, (string) $key)) {
                if ($bestKey === null || strlen((string) $key) > strlen($bestKey)) {
                    $bestKey = (string) $key;
                }
            }
        }

        return $bestKey !== null ? $this->normalize($table[$bestKey]) : null;
    }

    /**
     * Get the merged pricing table for the given provider.
     *
     * @return array<string, ModelPricing|array<string, mixed>>
     */
    protected function table(string $provider): array
    {
        return array_replace(
            static::defaults()[$provider] ?? [],
            $this->overrides[$provider] ?? [],
            $this->registered[$provider] ?? [],
        );
    }

    /**
     * Normalize a table entry into a ModelPricing instance.
     *
     * @param  ModelPricing|array<string, mixed>  $rates
     */
    protected function normalize(ModelPricing|array $rates): ModelPricing
    {
        return $rates instanceof ModelPricing ? $rates : ModelPricing::fromArray($rates);
    }

    /**
     * The built-in default price table, in USD per 1,000,000 tokens.
     *
     * These are estimates as of 2026-06 and are intended as a convenience; they
     * can drift as providers change pricing. Override or extend them via the
     * config('ai.pricing.models') array or PriceList::register().
     *
     * @return array<string, array<string, array<string, float>>>
     */
    public static function defaults(): array
    {
        return [
            'openai' => [
                'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
                'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
                'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
                'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
                'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
                'o4-mini' => ['input' => 1.10, 'output' => 4.40],
                'o3' => ['input' => 2.00, 'output' => 8.00],
            ],
            'anthropic' => [
                'claude-opus-4' => ['input' => 15.00, 'output' => 75.00],
                'claude-sonnet-4' => ['input' => 3.00, 'output' => 15.00],
                'claude-haiku-4' => ['input' => 1.00, 'output' => 5.00],
                'claude-3-5-haiku' => ['input' => 0.80, 'output' => 4.00],
                'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
            ],
            'gemini' => [
                'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
                'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
            ],
        ];
    }
}
