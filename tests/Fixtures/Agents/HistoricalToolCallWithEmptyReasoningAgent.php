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

class HistoricalToolCallWithEmptyReasoningAgent implements Agent, Conversational
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function provider(): string
    {
        return 'deepseek';
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('check stock'),
            new AssistantMessage(
                'Found the products.',
                collect([
                    new ToolCall('call_empty', 'SearchProducts', ['keyword' => 'lampu'], 'call_empty'),
                ]),
                ['reasoning_content' => '']
            ),
            new ToolResultMessage(collect([
                new ToolResult('call_empty', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_empty'),
            ])),
            new UserMessage('tell me more'),
        ];
    }
}
