<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Gateway\LayaGateway;

class LayaProvider extends Provider implements ClassificationProvider
{
    use Concerns\Classifies;
    use Concerns\HasClassificationGateway;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the credentials for the Laya provider (API key is optional).
     */
    #[\Override]
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'] ?? '',
        ];
    }

    /**
     * Get the name of the default classification model.
     *
     * Laya routes any name that is not one of its checkpoints to the best checkpoint for the state's language.
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? 'auto';
    }

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new LayaGateway;
    }
}
