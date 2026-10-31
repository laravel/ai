<?php

namespace Tests\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

trait GeminiHelpers
{
    protected function fakeInteraction(array $steps, array $usage = [], string $status = 'completed'): array
    {
        return [
            'id' => 'int_123',
            'model' => 'gemini-3.7-flash',
            'status' => $status,
            'steps' => $steps,
            'usage' => array_merge([
                'total_input_tokens' => 10,
                'total_output_tokens' => 5,
                'total_tokens' => 15,
            ], $usage),
        ];
    }

    protected function modelOutput(string $text, array $annotations = []): array
    {
        return [
            'type' => 'model_output',
            'status' => 'done',
            'content' => [array_filter([
                'type' => 'text',
                'text' => $text,
                'annotations' => $annotations ?: null,
            ])],
        ];
    }

    protected function thoughtStep(string $text): array
    {
        return [
            'type' => 'thought',
            'status' => 'done',
            'summary' => [['type' => 'text', 'text' => $text]],
        ];
    }

    protected function functionCallStep(string $name, array $arguments = [], string $id = 'call_123'): array
    {
        return [
            'type' => 'function_call',
            'status' => 'done',
            'id' => $id,
            'name' => $name,
            'arguments' => (object) $arguments,
        ];
    }

    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response($this->fakeInteraction([$this->modelOutput($text)]));
    }

    protected function fakeThinkingResponse(array $steps): PromiseInterface
    {
        return Http::response($this->fakeInteraction($steps, ['total_thought_tokens' => 3, 'total_tokens' => 18]));
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        return Http::response($this->fakeInteraction([
            $this->functionCallStep($toolName, [], $callId ?? 'call_123'),
        ]));
    }

    protected function fakeStructuredResponse(array $data): PromiseInterface
    {
        return Http::response($this->fakeInteraction([$this->modelOutput(json_encode($data))]));
    }

    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response($this->fakeInteraction([
            $this->functionCallStep('FixedNumberGenerator', [], 'call_'.uniqid()),
        ]));
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: '.json_encode($event);
        }

        return implode("\n\n", $lines)."\n\n";
    }

    protected function stepStart(int $index, array $step): array
    {
        return ['event_type' => 'step.start', 'index' => $index, 'step' => $step];
    }

    protected function stepDelta(int $index, string $type, string $text): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => $type, 'text' => $text]];
    }

    protected function argumentsDelta(int $index, string $partial): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => 'arguments_delta', 'arguments' => $partial]];
    }

    protected function stepStop(int $index): array
    {
        return ['event_type' => 'step.stop', 'index' => $index];
    }

    protected function interactionCompleted(array $usage = [], string $status = 'completed'): array
    {
        // Gemini's completed event carries the usage and status only, never the steps...
        return [
            'event_type' => 'interaction.completed',
            'interaction' => Arr::except($this->fakeInteraction([], $usage, $status), 'steps'),
        ];
    }
}
