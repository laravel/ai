<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Symfony\Component\HttpFoundation\Response;

class AgentResponse extends TextResponse implements Responsable
{
    public string $invocationId;

    public ?string $conversationId = null;

    public ?object $conversationUser = null;

    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Create a fake response that is waiting for approval.
     *
     * @param  array<int, PendingApproval>|Collection<int, PendingApproval>  $pendingApprovals
     */
    public static function fakeAwaitingApproval(array|Collection $pendingApprovals): self
    {
        return (new self('fake-invocation', '', new Usage, new Meta))
            ->withPendingApprovals(Collection::make($pendingApprovals));
    }

    /**
     * Set the conversation UUID and participant for this response.
     */
    public function withinConversation(string $conversationId, ?object $conversationUser = null): self
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Execute a callback with this response.
     */
    public function then(callable $callback): self
    {
        $callback($this);

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($this->awaitingApproval()) {
            return response()->json([
                'status' => 'awaiting_approval',
                'conversation_id' => $this->conversationId,
                'approvals' => $this->pendingApprovals->toArray(),
            ]);
        }

        $payload = [
            'status' => 'complete',
            'conversation_id' => $this->conversationId,
            'reply' => $this->text,
        ];

        // A bare rejection stops the loop before any model step, leaving an empty reply; surface only that turn's tool results so a normal completion's internal tool output is never exposed...
        if (blank($this->text) && $this->toolResults->isNotEmpty() && $this->steps->isEmpty()) {
            $payload['tool_results'] = $this->toolResults->map(fn (ToolResult $result) => [
                'id' => $result->id,
                'tool' => $result->name,
                'result' => $result->result,
            ])->values()->toArray();
        }

        return response()->json($payload);
    }
}
