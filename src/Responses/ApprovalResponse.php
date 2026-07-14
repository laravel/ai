<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\Data\ToolResult;
use Symfony\Component\HttpFoundation\Response;

class ApprovalResponse implements Responsable
{
    public function __construct(public AgentResponse $response) {}

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($this->response->awaitingApproval()) {
            return response()->json([
                'status' => 'awaiting_approval',
                'conversation_id' => $this->response->conversationId,
                'approvals' => $this->response->pendingApprovals->toArray(),
            ]);
        }

        $payload = [
            'status' => 'complete',
            'conversation_id' => $this->response->conversationId,
            'reply' => $this->response->text,
        ];

        if (blank($this->response->text) && $this->response->toolResults->isNotEmpty() && $this->response->steps->isEmpty()) {
            $payload['tool_results'] = $this->response->toolResults->map(fn (ToolResult $result) => [
                'id' => $result->id,
                'tool' => $result->name,
                'result' => $result->result,
            ])->values()->toArray();
        }

        return response()->json($payload);
    }
}
