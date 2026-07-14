<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class Decision
{
    /**
     * @param  array<string, mixed>|null  $arguments
     * @param  array<string, Decision>  $decisions  keyed by tool call id when this decision bundles a collection
     */
    private function __construct(
        public readonly ?string $action = null,
        public readonly ?string $result = null,
        public readonly ?array $arguments = null,
        public readonly array $decisions = [],
    ) {}

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
        // A whitespace-only reason carries no message for the model, so it behaves as a bare rejection...
        return new self('reject', result: blank($result) ? null : $result);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    /**
     * Bundle one decision per tool call id, accepting booleans as shorthand and a '*' wildcard for undecided calls.
     *
     * @param  array<string, Decision|bool>  $decisions
     */
    public static function collection(array $decisions): self
    {
        $normalized = [];

        foreach ($decisions as $id => $decision) {
            $decision = match (true) {
                $decision === true => self::approve(),
                $decision === false => self::reject(),
                $decision instanceof self => $decision,
                default => throw new InvalidArgumentException('Tool approval decisions must be Decision instances or booleans.'),
            };

            // A leaf decision must name a concrete action; a nested collection has none and would otherwise fall through to an approval...
            if ($decision->isCollection()) {
                throw new InvalidArgumentException('Tool approval decisions may not nest another decision collection.');
            }

            if ($id === '*' && $decision->isEdited()) {
                throw new InvalidArgumentException('The wildcard decision may not use the edit action.');
            }

            $normalized[$id] = $decision;
        }

        return new self(decisions: $normalized);
    }

    /**
     * Extract tool approval decisions from a Vercel AI SDK "useChat" request, or null when it carries none.
     */
    public static function tryFrom(Request $request): ?self
    {
        $messages = $request->input('messages');

        try {
            return is_array($messages) ? self::fromUiMessages($messages) : null;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['messages' => $exception->getMessage()]);
        }
    }

    /**
     * Extract tool approval decisions from a list of Vercel AI SDK UI messages.
     *
     * @param  array<int, mixed>  $messages
     */
    private static function fromUiMessages(array $messages): ?self
    {
        $message = end($messages);

        // Approval responses may only be awaiting resumption on a trailing assistant message...
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant') {
            return null;
        }

        $decisions = [];

        foreach ($message['parts'] ?? [] as $part) {
            if (! is_array($part) || ($part['state'] ?? null) !== 'approval-responded') {
                continue;
            }

            $reason = $part['approval']['reason'] ?? null;
            $id = $part['toolCallId'] ?? null;

            if (! is_string($id)
                || ! is_bool($part['approval']['approved'] ?? null)
                || ($reason !== null && ! is_string($reason))) {
                throw new InvalidArgumentException('Tool approval response parts must contain a tool call id and an approval decision.');
            }

            // A client that submits two responses for one call is ambiguous; refuse rather than silently last-win...
            if (array_key_exists($id, $decisions)) {
                throw new InvalidArgumentException('Tool approval response parts contain conflicting decisions for the same tool call.');
            }

            $decisions[$id] = $part['approval']['approved']
                ? self::approve()
                : self::reject($reason);
        }

        return $decisions === [] ? null : self::collection($decisions);
    }

    public function isApproved(): bool
    {
        return $this->action === 'approve';
    }

    public function isRejected(): bool
    {
        return $this->action === 'reject';
    }

    public function isEdited(): bool
    {
        return $this->action === 'edit';
    }

    /**
     * Determine whether this decision bundles a collection rather than naming a single action.
     */
    public function isCollection(): bool
    {
        return $this->action === null;
    }
}
