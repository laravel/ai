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
        public ?string $action = null,
        public ?string $result = null,
        public ?array $arguments = null,
        public array $decisions = [],
    ) {}

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
        return new self('reject', result: $result);
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
                        ? self::approve()
                        : self::reject($reason),
                ];
            });

        return $decisions->isEmpty() ? null : self::collection($decisions->all());
    }

    public function isRejected(): bool
    {
        return $this->action === 'reject';
    }

    public function isEdited(): bool
    {
        return $this->action === 'edit';
    }
}
