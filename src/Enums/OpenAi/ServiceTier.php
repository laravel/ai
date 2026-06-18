<?php

namespace Laravel\Ai\Enums\OpenAi;

use Laravel\Ai\Contracts\ServiceTier as ServiceTierContract;

/**
 * Service tiers supported by the OpenAI API.
 *
 * Also applies to Azure OpenAI, which reuses the OpenAI request builder.
 *
 * @see https://platform.openai.com/docs/api-reference/responses/create
 */
enum ServiceTier: string implements ServiceTierContract
{
    case Auto = 'auto';
    case Default = 'default';
    case Flex = 'flex';
    case Priority = 'priority';
}
