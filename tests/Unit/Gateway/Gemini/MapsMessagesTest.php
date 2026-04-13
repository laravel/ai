<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\Gemini\Concerns\BuildsTextRequests;
use Laravel\Ai\Gateway\Gemini\Concerns\MapsMessages;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

function geminiMapper(): object
{
    return new class
    {
        use BuildsTextRequests;
        use MapsMessages;

        public function map(array $messages): array
        {
            return $this->mapMessagesToContents($messages);
        }

        protected function mapAttachments(Collection $attachments): array
        {
            return [];
        }
    };
}

test('persisted assistant tool call replays its id onto functionCall', function () {
    // This is the laravel/ai#388 "I saved it in the DB" scenario: the prior
    // turn has a synthesized id stored on the ToolCall + ToolResult. On
    // replay, mapAssistantMessage must echo the same id onto functionCall so
    // the matching functionResponse id is not orphaned.
    $persistedId = 'persisted-uuid-from-db';

    $contents = geminiMapper()->map([
        new UserMessage('Generate'),
        new AssistantMessage('', collect([
            new ToolCall($persistedId, 'FixedNumberGenerator', []),
        ])),
        new ToolResultMessage(collect([
            new ToolResult($persistedId, 'FixedNumberGenerator', [], 72019),
        ])),
    ]);

    $functionCall = null;
    $functionResponse = null;

    foreach ($contents as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
            }
            if (isset($part['functionResponse'])) {
                $functionResponse = $part['functionResponse'];
            }
        }
    }

    expect($functionCall)->not->toBeNull()
        ->and($functionResponse)->not->toBeNull()
        ->and($functionCall['id'] ?? null)->toBe($persistedId)
        ->and($functionResponse['id'] ?? null)->toBe($persistedId);
});

test('replay omits id on both sides when persisted tool call had no id', function () {
    // Pre-id-aware data — both sides should be id-less so they still match.
    $contents = geminiMapper()->map([
        new UserMessage('Generate'),
        new AssistantMessage('', collect([
            new ToolCall('', 'FixedNumberGenerator', []),
        ])),
        new ToolResultMessage(collect([
            new ToolResult('', 'FixedNumberGenerator', [], 72019),
        ])),
    ]);

    $functionCall = null;
    $functionResponse = null;

    foreach ($contents as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
            }
            if (isset($part['functionResponse'])) {
                $functionResponse = $part['functionResponse'];
            }
        }
    }

    expect($functionCall)->not->toBeNull()
        ->and($functionResponse)->not->toBeNull()
        ->and($functionCall)->not->toHaveKey('id')
        ->and($functionResponse)->not->toHaveKey('id');
});
