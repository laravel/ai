<?php

namespace Laravel\Ai\AgUi;

class Hydration
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $interrupts
     */
    public function __construct(
        protected array $messages,
        protected array $interrupts,
    ) {}

    /**
     * Get the restored AG-UI messages.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Get the interrupts the restored messages are still waiting on.
     *
     * @return list<array<string, mixed>>
     */
    public function interrupts(): array
    {
        return $this->interrupts;
    }
}
