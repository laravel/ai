<?php

namespace Laravel\Ai\Vercel;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\ToolApproval;

class ToolApprovalResponses
{
    /**
     * Extract tool approval decisions from a Vercel AI SDK "useChat" request.
     */
    public static function fromRequest(Request $request): ?ToolApproval
    {
        $messages = $request->input('messages');

        return is_array($messages) ? static::fromMessages($messages) : null;
    }

    /**
     * Extract tool approval decisions from a list of Vercel AI SDK UI messages.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function fromMessages(array $messages): ?ToolApproval
    {
        $message = end($messages);

        // Approval responses may only be awaiting resumption on a trailing assistant message...
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant') {
            return null;
        }

        $decisions = collect($message['parts'] ?? [])
            ->filter(fn ($part) => is_array($part) && ($part['state'] ?? null) === 'approval-responded')
            ->mapWithKeys(function (array $part) {
                if (! is_string($part['toolCallId'] ?? null) || ! is_bool($part['approval']['approved'] ?? null)) {
                    throw new InvalidArgumentException('Tool approval response parts must contain a tool call id and an approval decision.');
                }

                return [
                    $part['toolCallId'] => $part['approval']['approved']
                        ? Approval::approve()
                        : Approval::reject($part['approval']['reason'] ?? null),
                ];
            });

        return $decisions->isEmpty() ? null : new ToolApproval($decisions->all());
    }
}
