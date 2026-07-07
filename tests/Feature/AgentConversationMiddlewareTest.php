<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\TrimmingAgent;

function recordingGateway()
{
    return new class implements TextGateway
    {
        /** @var Message[] */
        public array $received = [];

        public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
        {
            $this->received = $messages;

            return new TextResponse('ok', new Usage, new Meta($provider->name(), $model));
        }

        public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): Generator
        {
            yield from [];
        }

        public function onToolInvocation(Closure $invoking, Closure $invoked): self
        {
            return $this;
        }
    };
}

test('a trimming agent still returns its response with middleware active', function () {
    TrimmingAgent::fake(['Trimmed answer.']);

    $response = (new TrimmingAgent)->prompt('Current question');

    expect($response->text)->toBe('Trimmed answer.');
});

test('conversation middleware trims the messages sent to the provider', function () {
    $recorder = recordingGateway();

    Ai::textProvider('openai')->useTextGateway($recorder);

    (new TrimmingAgent)->prompt('What is my latest question?', provider: 'openai');

    $contents = array_map(fn ($message) => $message->content, $recorder->received);

    expect($recorder->received)->toHaveCount(3)
        ->and($contents)->toBe(['Message two', 'Reply two', 'What is my latest question?'])
        ->and($contents)->not->toContain('Message one');
});

test('an agent without conversation middleware sends the full history', function () {
    $recorder = recordingGateway();

    Ai::textProvider('openai')->useTextGateway($recorder);

    (new ConversationalAgent)->prompt('Hello there', provider: 'openai');

    $contents = array_map(fn ($message) => $message->content, $recorder->received);

    expect($recorder->received)->toHaveCount(2)
        ->and($contents)->toBe(['My name is Taylor Otwell', 'Hello there']);
});
