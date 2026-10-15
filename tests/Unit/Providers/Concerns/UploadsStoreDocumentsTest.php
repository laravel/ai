<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Concerns\UploadsStoreDocuments;
use Laravel\Ai\Providers\OpenAiProvider;

test('uploading a document throws when the store gateway cannot upload directly', function (): void {
    $provider = new class(Mockery::mock(Gateway::class), ['name' => 'openai', 'driver' => 'openai'], Mockery::mock(Dispatcher::class)) extends OpenAiProvider
    {
        use UploadsStoreDocuments;
    };

    $provider->useStoreGateway(Mockery::mock(StoreGateway::class));

    $provider->uploadDocumentToStore('vs_1', Document::fromString('Hello', 'text/plain'));
})->throws(LogicException::class, 'does not support uploading documents directly');
