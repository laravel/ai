<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Reranking;
use Throwable;

use function Laravel\Ai\agent;

class DiagnoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:diagnose {--provider=* : Diagnose specific providers} {--skip-requests : Skip live test requests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose AI provider configuration and connectivity';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $providers = $this->configuredProviders();

        if (empty($providers)) {
            $this->error('No AI providers are configured. Set ai.providers in your configuration.');
        }

        $this->info('AI Diagnostics');

        $rows = [];
        $failures = 0;

        foreach ($providers as $name => $config) {
            $hasKey = $this->hasKey($name, $config);
            $provider = $this->resolveProvider($name);

            if (! $hasKey || ! $provider) {
                $rows[] = [
                    Str::ucfirst($name),
                    data_get($config, 'driver', 'N/A'),
                    "Missing key",
                    'N/A',
                    'Unable to resolve provider'
                ];
                $failures++;

                continue;
            }

            $capabilities = implode(', ', $this->capabilitiesFor($provider));
            $requestStatus = $this->runTestRequest($name, $provider, $hasKey);

            if ($requestStatus['success'] === false) {
                $failures++;
            }

            $rows[] = [Str::ucfirst($name), $provider->driver(), 'SET', $capabilities, $requestStatus['label']];
        }

        $this->table(['Provider', 'Driver', 'Key', 'Capabilities', 'Request'], $rows);
    }

    /**
     * Get the configured providers that should be diagnosed.
     */
    protected function configuredProviders(): array
    {
        $providers = config('ai.providers', []);
        $only = array_filter($this->option('provider'));

        if (empty($only)) {
            return $providers;
        }

        $selected = [];

        foreach ($only as $name) {
            if (! array_key_exists($name, $providers)) {
                $this->warn("Provider [{$name}] is not configured in ai.providers.");

                continue;
            }

            $selected[$name] = $providers[$name];
        }

        return $selected;
    }

    /**
     * Determine if a provider has a key.
     */
    protected function hasKey(string $name, array $config): bool
    {
        $key = data_get($config, 'key');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Resolve a provider instance from the manager.
     */
    protected function resolveProvider(string $name): ?Provider
    {
        $manager = app(AiManager::class);

        try {
            if (method_exists($manager, 'instance')) {
                return $manager->instance($name);
            }

            if (method_exists($manager, 'driver')) {
                return $manager->driver($name);
            }
        } catch (Throwable $e) {
            $this->error("Failed to resolve provider [{$name}]: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Get the list of capabilities supported by the provider.
     */
    protected function capabilitiesFor(Provider $provider): array
    {
        $capabilities = [];

        $map = [
            TextProvider::class => 'text',
            ImageProvider::class => 'image',
            AudioProvider::class => 'audio',
            TranscriptionProvider::class => 'transcription',
            EmbeddingProvider::class => 'embeddings',
            RerankingProvider::class => 'reranking',
            FileProvider::class => 'files',
            StoreProvider::class => 'stores',
            SupportsWebSearch::class => 'web_search',
            SupportsWebFetch::class => 'web_fetch',
            SupportsFileSearch::class => 'file_search',
        ];

        foreach ($map as $interface => $label) {
            if ($provider instanceof $interface) {
                $capabilities[] = $label;
            }
        }

        return $capabilities;
    }

    /**
     * Run the minimal test request for the provider.
     */
    protected function runTestRequest(string $name, Provider $provider, bool $hasKey): array
    {
        if ($this->option('skip-requests')) {
            return ['label' => 'SKIPPED', 'success' => null];
        }

        if (! $hasKey) {
            return ['label' => 'SKIPPED (missing key)', 'success' => null];
        }

        $probe = $this->probeFor($name, $provider);

        if (! $probe) {
            return ['label' => 'SKIPPED (no requestable capabilities)', 'success' => null];
        }

        try {
            $probe();

            return ['label' => 'OK', 'success' => true];
        } catch (Throwable $e) {
            return ['label' => 'FAILED - '.$e->getMessage(), 'success' => false];
        }
    }

    /**
     * Get the probe closure for a provider.
     */
    protected function probeFor(string $name, Provider $provider): ?callable
    {
        if ($provider instanceof TextProvider) {
            return function () use ($name) {
                $agent = agent('You are a diagnostic assistant.');

                $agent->prompt('diagnostic ping', provider: $name);
            };
        }

        if ($provider instanceof EmbeddingProvider) {
            return fn () => Embeddings::for(['diagnostic ping'])->generate(provider: $name);
        }

        if ($provider instanceof RerankingProvider) {
            return fn () => Reranking::of(['diagnostic ping', 'diagnostic pong'])
                ->limit(1)
                ->rerank('diagnostic ping', $name);
        }

        if ($provider instanceof ImageProvider) {
            return fn () => Image::of('diagnostic image')
                ->square()
                ->generate(provider: $name);
        }

        if ($provider instanceof AudioProvider) {
            return fn () => Audio::of('diagnostic audio')
                ->generate(provider: $name);
        }

        return null;
    }
}
