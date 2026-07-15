<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class Decision
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    private function __construct(
        public readonly string $action,
        public readonly ?string $result = null,
        public readonly ?array $arguments = null,
    ) {}

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
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
     * A blanket approval for every pending tool call.
     *
     * @return array<string, Decision>
     */
    public static function approveAll(): array
    {
        return ['*' => self::approve()];
    }

    /**
     * A blanket rejection for every pending tool call.
     *
     * @return array<string, Decision>
     */
    public static function rejectAll(): array
    {
        return ['*' => self::reject()];
    }

    /**
     * Normalize an id-keyed decision map, accepting booleans as shorthand and a '*' wildcard for undecided calls.
     *
     * @param  array<string, Decision|bool>  $decisions
     * @return array<string, Decision>
     */
    public static function normalize(array $decisions): array
    {
        if ($decisions === []) {
            throw new InvalidArgumentException('Tool approval decisions may not be empty.');
        }

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

        return $normalized;
    }

    /**
     * Extract tool approval decisions from a Vercel AI SDK "useChat" request, keyed by tool call id, or null when it carries none.
     *
     * @return array<string, Decision>|null
     */
    public static function tryFrom(Request $request): ?array
    {
        $messages = $request->input('messages');

        try {
            return is_array($messages) ? self::fromUiMessages($messages) : null;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['messages' => $exception->getMessage()]);
        }
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
     * Extract tool approval decisions from a list of Vercel AI SDK UI messages.
     *
     * @param  array<int, mixed>  $messages
     * @return array<string, Decision>|null
     */
    private static function fromUiMessages(array $messages): ?array
    {
        $message = end($messages);

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

            if ($id === '*') {
                throw new InvalidArgumentException('Tool approval response parts may not target the wildcard tool call id.');
            }

            if (! is_string($id)
                || ! is_bool($part['approval']['approved'] ?? null)
                || ($reason !== null && ! is_string($reason))) {
                throw new InvalidArgumentException('Tool approval response parts must contain a tool call id and an approval decision.');
            }

            if (array_key_exists($id, $decisions)) {
                throw new InvalidArgumentException('Tool approval response parts contain conflicting decisions for the same tool call.');
            }

            $decisions[$id] = $part['approval']['approved']
                ? self::approve()
                : self::reject($reason);
        }

        return $decisions === [] ? null : $decisions;
    }
}
