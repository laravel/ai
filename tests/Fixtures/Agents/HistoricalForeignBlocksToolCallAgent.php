<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class HistoricalForeignBlocksToolCallAgent implements Agent, Conversational
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('check stock'),
            new AssistantMessage(
                'Found the products.',
                collect([
                    new ToolCall('call_1', 'SearchProducts', ['keyword' => 'lampu'], 'call_1'),
                ]),
                [
                    ['type' => 'thinking', 'thinking' => 'I need to search for lampu products.'],
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'SearchProducts', 'input' => ['keyword' => 'lampu']],
                ],
                'anthropic',
            ),
            new ToolResultMessage(collect([
                new ToolResult('call_1', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_1'),
            ])),
        ];
    }
}
