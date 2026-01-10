<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Stringable;
use Laravel\Ai\Console\Commands\ChatCommand;
use Laravel\Ai\Console\Commands\MakeAgentCommand;
use Laravel\Ai\Console\Commands\MakeToolCommand;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(AiManager::class, fn ($app): AiManager => new AiManager($app));

        $this->mergeConfigFrom(__DIR__.'/../config/ai.php', 'ai');
    }

    /**
     * Bootstrap the package's services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }

        // Embeddings macro..
        Stringable::macro('toEmbeddings', function (
            ?string $provider = null,
            ?int $dimensions = null,
            ?string $model = null
        ) {
            return Ai::embeddingProvider($provider ?? config('ai.default_for_embeddings'))
                ->embeddings([$this->value], $dimensions, $model)
                ->embeddings[0];
        });

        // Reranking macro..
        Collection::macro('rerank', function (
            Closure|array|string $by,
            string $query,
            ?int $limit = null,
            array|string|null $provider = null,
            ?string $model = null
        ) {
            $resolver = match (true) {
                $by instanceof Closure => $by,
                is_array($by) => fn ($item) => json_encode(
                    (new Collection($by))->mapWithKeys(fn ($field) => [$field => data_get($item, $field)])->all()
                ),
                default => fn ($item) => data_get($item, $by),
            };

            $response = Reranking::of($this->map($resolver)->values()->all())
                ->limit($limit)
                ->rerank($query, $provider, $model);

            return (new Collection($response->results))->map(
                fn ($result) => $this->values()[$result->index]
            );
        });
    }

    /**
     * Register the package's console commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            // ChatCommand::class,
            MakeAgentCommand::class,
            MakeToolCommand::class,
        ]);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai.php' => config_path('ai.php'),
        ], ['ai', 'ai-config']);

        $this->publishes([
            __DIR__.'/../stubs/agent.stub' => base_path('stubs/agent.stub'),
            __DIR__.'/../stubs/structured-agent.stub' => base_path('stubs/structured-agent.stub'),
            __DIR__.'/../stubs/tool.stub' => base_path('stubs/tool.stub'),
        ], 'ai-stubs');
    }
}
