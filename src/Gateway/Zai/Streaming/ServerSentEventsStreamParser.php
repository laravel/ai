<?php

namespace Laravel\Ai\Gateway\Zai\Streaming;

use Generator;
use JsonException;
use Laravel\Ai\Gateway\Zai\Contracts\ServerSentEventsStreamParserInterface;

class ServerSentEventsStreamParser implements ServerSentEventsStreamParserInterface
{
    public function parse(string $sseData): Generator
    {
        $lines = explode("\n", $sseData);

        foreach ($lines as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed === null) {
                continue;
            }

            if ($parsed['isDone']) {
                yield ['type' => 'done'];

                return;
            }

            try {
                $chunk = json_decode($parsed['data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            foreach ($this->processChunk($chunk) as $event) {
                yield $event;
            }
        }
    }

    protected function parseLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || ! str_starts_with($line, 'data: ')) {
            return null;
        }

        $data = substr($line, 6);

        return [
            'data' => $data,
            'isDone' => $data === '[DONE]',
        ];
    }

    protected function processChunk(array $chunk): Generator
    {
        if (! isset($chunk['choices'][0])) {
            return;
        }

        $choice = $chunk['choices'][0];
        $id = $chunk['id'] ?? null;
        $timestamp = $chunk['created'] ?? time();

        if (isset($choice['delta']['reasoning_content'])) {
            yield [
                'type' => 'reasoning_delta',
                'id' => $id ?? uniqid('msg_', true),
                'reasoningId' => $id ?? uniqid('msg_', true),
                'delta' => $choice['delta']['reasoning_content'],
                'timestamp' => $timestamp,
            ];
        }

        if (isset($choice['delta']['content'])) {
            yield [
                'type' => 'text_delta',
                'id' => $id ?? uniqid('msg_', true),
                'messageId' => $id ?? uniqid('msg_', true),
                'delta' => $choice['delta']['content'],
                'timestamp' => $timestamp,
            ];
        }

        if (isset($choice['delta']['tool_calls'])) {
            foreach ($choice['delta']['tool_calls'] as $toolCall) {
                yield [
                    'type' => 'tool_call_delta',
                    'index' => $toolCall['index'] ?? 0,
                    'id' => $toolCall['id'] ?? '',
                    'name' => $toolCall['function']['name'] ?? '',
                    'arguments' => $toolCall['function']['arguments'] ?? '',
                ];
            }
        }

        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null && isset($chunk['usage'])) {
            yield [
                'type' => 'usage',
                'promptTokens' => $chunk['usage']['prompt_tokens'] ?? 0,
                'completionTokens' => $chunk['usage']['completion_tokens'] ?? 0,
                'finishReason' => $choice['finish_reason'],
            ];
        }
    }
}
