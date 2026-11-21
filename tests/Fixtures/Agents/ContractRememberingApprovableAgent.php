<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

/**
 * An agent that implements the RemembersConversations contract itself instead of using the trait.
 */
class ContractRememberingApprovableAgent implements Agent, HasTools, RemembersConversations
{
    use Promptable;

    public ?string $conversationId = null;

    public ?object $participant = null;

    public function instructions(): string
    {
        return 'You are a helpful assistant that always uses the available tool to generate a number.';
    }

    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }

    public function messages(): iterable
    {
        return $this->conversationId === null
            ? []
            : resolve(ConversationStore::class)->getLatestConversationMessages($this->conversationId, 100)->all();
    }

    public function forParticipant(object $participant): static
    {
        [$this->conversationId, $this->participant] = [null, $participant];

        return $this;
    }

    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        [$this->conversationId, $this->participant] = [$conversationId, $as];

        return $this;
    }

    public function continueOrStart(?string $conversationId, object $as): static
    {
        return $conversationId === null ? $this->forParticipant($as) : $this->continue($conversationId, $as);
    }

    public function continueLastConversation(object $as): static
    {
        return $this->forParticipant($as);
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return $this->participant !== null;
    }

    public function conversationParticipant(): ?object
    {
        return $this->participant;
    }
}
