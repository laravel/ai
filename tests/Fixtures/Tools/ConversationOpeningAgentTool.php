<?php

namespace Tests\Fixtures\Tools;

use Generator;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;

/**
 * An agent tool that opens a conversation of its own for every delegation before running it.
 */
class ConversationOpeningAgentTool extends AgentTool
{
    /**
     * The conversations opened so far, in order.
     *
     * @var list<string>
     */
    public array $opened = [];

    public function handle(Request $request): string
    {
        $this->openConversation($request);

        return parent::handle($request);
    }

    public function stream(Request $request): Generator
    {
        // A structured sub-agent cannot stream, so the parent's stream() falls back to handle(), which opens it...
        if (! $this->agent() instanceof HasStructuredOutput) {
            $this->openConversation($request);
        }

        return yield from parent::stream($request);
    }

    protected function openConversation(Request $request): void
    {
        $this->opened[] = $conversationId = resolve(ConversationStore::class)->storeConversation(null, null, $request['task']);

        $this->agent()->continue($conversationId, $this->agent()->conversationParticipant());
    }
}
