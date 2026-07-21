<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('Azure rejects a deferred tool because it does not support hosted tool search', function () {
    Http::fake(['my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('ok')]);

    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new NonStrictTool, new DeferredTool];
        }
    };

    expect(fn () => $agent->prompt('Hi', provider: 'azure'))
        ->toThrow(LogicException::class, 'does not support tool search');

    Http::assertNothingSent();
});
