<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
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

test('Azure rejects a ToolSearch tool because it does not support hosted tool search', function () {
    Http::fake(['my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('ok')]);

    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(Lab|string $provider): iterable
        {
            return [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])];
        }
    };

    expect(fn () => $agent->prompt('Hi', provider: 'azure'))
        ->toThrow(LogicException::class, 'does not support tool search');

    Http::assertNothingSent();
});
