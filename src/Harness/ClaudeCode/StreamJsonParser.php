<?php

namespace Laravel\Ai\Harness\ClaudeCode;

use Illuminate\Support\Collection;
use Laravel\Ai\Harness\HarnessException;
use Laravel\Ai\Harness\HarnessResult;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events;

use function Laravel\Ai\ulid;

class StreamJsonParser
{
    public ?HarnessResult $result = null;

    protected array $events = [];

    protected array $calls = [];

    protected array $partialMessages = [];

    protected array $blocks = [];

    protected ?string $messageId = null;

    protected bool $started = false;

    public function __construct(protected HarnessRun $run) {}

    /** @return array<Events\StreamEvent> */
    public function parse(string $line): array
    {
        if (trim($line) === '') {
            return [];
        }

        $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['type'])) {
            throw new HarnessException('Invalid Claude Code stream message.', $this->run->sessionId);
        }

        if (($data['parent_tool_use_id'] ?? null) !== null) {
            return [];
        }

        $events = [];

        if ($data['type'] === 'system' && ($data['subtype'] ?? '') === 'init' && ! $this->started) {
            $this->started = true;
            $this->run->sessionId = $data['session_id'] ?? $this->run->sessionId;
            $this->run->model = $data['model'] ?? $this->run->model;
            $events[] = new Events\StreamStart(ulid(), $this->run->harness, $this->run->model, time(), ['session_id' => $this->run->sessionId]);
        } elseif ($data['type'] === 'stream_event') {
            $events = $this->partial($data['event']);
        } elseif ($data['type'] === 'assistant') {
            $message = $data['message'];
            $id = $message['id'] ?? ulid();

            foreach ($message['content'] ?? [] as $index => $block) {
                $blockId = $id.':'.$index;

                if ($block['type'] === 'tool_use') {
                    if (isset($this->calls[$block['id']])) {
                        continue;
                    }

                    $call = new Data\ToolCall($block['id'], $this->toolName($block['name']), $block['input'] ?? []);
                    $this->calls[$call->id] = $call;
                    $events[] = new Events\ToolCall(ulid(), $call, time());
                } elseif (! isset($this->partialMessages[$id])) {
                    $events = [...$events, ...match ($block['type']) {
                        'text' => [new Events\TextStart(ulid(), $blockId, time()), new Events\TextDelta(ulid(), $blockId, $block['text'], time()), new Events\TextEnd(ulid(), $blockId, time())],
                        'thinking' => [new Events\ReasoningStart(ulid(), $blockId, time()), new Events\ReasoningDelta(ulid(), $blockId, $block['thinking'], time()), new Events\ReasoningEnd(ulid(), $blockId, time())],
                        default => [],
                    }];
                }
            }
        } elseif ($data['type'] === 'user') {
            foreach ($data['message']['content'] ?? [] as $block) {
                if (! is_array($block) || ($block['type'] ?? '') !== 'tool_result') {
                    continue;
                }

                $call = $this->calls[$block['tool_use_id']] ?? null;
                $failed = $block['is_error'] ?? false;
                $content = $block['content'] ?? '';
                $result = new Data\ToolResult($block['tool_use_id'], $call?->name ?? '', $call?->arguments ?? [], $content, failed: $failed);
                $events[] = new Events\ToolResult(ulid(), $result, ! $failed, $failed ? (is_string($content) ? $content : json_encode($content)) : null, time());
            }
        } elseif ($data['type'] === 'result') {
            if ($data['is_error'] ?? false) {
                throw new HarnessException(implode("\n", $data['errors'] ?? [$data['result'] ?? 'Claude Code failed.']), $this->run->sessionId);
            }

            $usage = $data['usage'] ?? [];
            $session = $data['session_id'] ?? $this->run->sessionId;
            $this->result = new HarnessResult(
                $data['result'] ?? Events\TextDelta::combine($this->events),
                new Data\Usage($usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0, $usage['cache_creation_input_tokens'] ?? 0, $usage['cache_read_input_tokens'] ?? 0),
                new Data\Meta($this->run->harness, $this->run->model, sessionId: $session),
                $session, new Collection(array_values($this->calls)),
                finishReason: ($data['subtype'] ?? 'success') === 'success' ? 'stop' : $data['subtype'],
            );
        }

        array_push($this->events, ...$events);

        return $events;
    }

    protected function partial(array $event): array
    {
        if ($event['type'] === 'message_start') {
            $this->messageId = $event['message']['id'];
            $this->partialMessages[$this->messageId] = true;
            $this->blocks = [];

            return [];
        }

        $index = $event['index'] ?? 0;
        $id = $this->messageId.':'.$index;

        if ($event['type'] === 'content_block_start') {
            $type = $event['content_block']['type'];
            $this->blocks[$index] = $type;

            return match ($type) {
                'text' => [new Events\TextStart(ulid(), $id, time()), ...(($event['content_block']['text'] ?? '') !== '' ? [new Events\TextDelta(ulid(), $id, $event['content_block']['text'], time())] : [])],
                'thinking' => [new Events\ReasoningStart(ulid(), $id, time())],
                default => [],
            };
        }

        if ($event['type'] === 'content_block_delta') {
            return match ($event['delta']['type']) {
                'text_delta' => [new Events\TextDelta(ulid(), $id, $event['delta']['text'], time())],
                'thinking_delta' => [new Events\ReasoningDelta(ulid(), $id, $event['delta']['thinking'], time())],
                default => [],
            };
        }

        if ($event['type'] === 'content_block_stop') {
            return match ($this->blocks[$index] ?? null) {
                'text' => [new Events\TextEnd(ulid(), $id, time())],
                'thinking' => [new Events\ReasoningEnd(ulid(), $id, time())],
                default => [],
            };
        }

        return [];
    }

    protected function toolName(string $name): string
    {
        $local = str_starts_with($name, 'mcp__laravel__') ? substr($name, 14) : null;

        return $local !== null && isset($this->run->tools[$local]) ? $local : $name;
    }
}
