<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class HistoricalReasoningWithToolsAgent implements Agent, Conversational, HasTools
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

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('What is 4+4?'),
            new AssistantMessage(
                'The answer is 8.',
                providerContentBlocks: ['reasoning_content' => 'Let me think... 4+4 = 8.']
            ),
        ];
    }
}
