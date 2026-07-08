<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Stringable;
use Throwable;

class AgentTool implements Tool
{
    public function __construct(protected Agent $agent, protected ?Agent $parent = null)
    {
        //
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->name()
            : class_basename($this->agent);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        if ($this->agent instanceof CanActAsTool) {
            return $this->agent->description();
        }

        return $this->agent instanceof RemembersConversations
            ? sprintf(
                'Delegates a task to the %s sub-agent and returns its response. The sub-agent continues the parent conversation, so it can see the history when handling the task.',
                $this->name(),
            )
            : sprintf(
                'Delegates a task to the %s sub-agent and returns its response. Pass a clear, self-contained task description as the sub-agent runs in isolation and has no access to the parent conversation history.',
                $this->name(),
            );
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        try {
            $this->continueParentConversation();

            return $this->agent->prompt((string) $request['task'])->text;
        } catch (Throwable $e) {
            return 'Agent failed: '.$e->getMessage();
        }
    }

    /**
     * Continue the parent's conversation so the sub-agent shares its history.
     *
     * Only applies when both the parent and the sub-agent remember conversations
     * and the parent is already participating in one; otherwise the sub-agent
     * runs in isolation, preserving the default behavior.
     */
    protected function continueParentConversation(): void
    {
        if (! $this->agent instanceof RemembersConversations ||
            ! $this->parent instanceof RemembersConversations) {
            return;
        }

        $conversationId = $this->parent->currentConversation();
        $participant = $this->parent->conversationParticipant();

        if ($conversationId === null || $participant === null) {
            return;
        }

        $this->agent->continue($conversationId, $participant);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task to delegate to this agent.')->required(),
        ];
    }

    /**
     * Get the underlying agent instance.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }
}
