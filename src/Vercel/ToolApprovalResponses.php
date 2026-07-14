<?php

namespace Laravel\Ai\Vercel;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decision;

class ToolApprovalResponses
{
    /**
     * Extract tool approval decisions from a Vercel AI SDK "useChat" request.
     */
    public static function fromRequest(Request $request): ?Decision
    {
        $messages = $request->input('messages');

        try {
            return is_array($messages) ? static::fromMessages($messages) : null;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['messages' => $exception->getMessage()]);
        }
    }

    /**
     * Extract tool approval decisions from a list of Vercel AI SDK UI messages.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function fromMessages(array $messages): ?Decision
    {
        $message = end($messages);

        // Approval responses may only be awaiting resumption on a trailing assistant message...
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant') {
            return null;
        }

        $decisions = collect($message['parts'] ?? [])
            ->filter(fn ($part) => is_array($part) && ($part['state'] ?? null) === 'approval-responded')
            ->mapWithKeys(function (array $part) {
                $reason = $part['approval']['reason'] ?? null;

                if (! is_string($part['toolCallId'] ?? null)
                    || ! is_bool($part['approval']['approved'] ?? null)
                    || ($reason !== null && ! is_string($reason))) {
                    throw new InvalidArgumentException('Tool approval response parts must contain a tool call id and an approval decision.');
                }

                return [
                    $part['toolCallId'] => $part['approval']['approved']
                        ? Decision::approve()
                        : Decision::reject($reason),
                ];
            });

        return $decisions->isEmpty() ? null : Decision::collection($decisions->all());
    }
}
