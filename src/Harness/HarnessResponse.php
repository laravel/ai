<?php

namespace Laravel\Ai\Harness;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class HarnessResponse extends HarnessResult implements Arrayable, JsonSerializable, Stringable
{
    public static function fromResult(HarnessResult $result): self
    {
        return new self(...get_object_vars($result));
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'session_id' => $this->sessionId,
            'tool_calls' => $this->toolCalls->toArray(),
            'pending_approvals' => $this->pendingApprovals->toArray(),
            'finish_reason' => $this->finishReason,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
