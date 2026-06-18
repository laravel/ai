<?php

namespace Laravel\Ai\Enums\Bedrock;

use Laravel\Ai\Contracts\ServiceTier as ServiceTierContract;

/**
 * Service tiers supported by the Amazon Bedrock Converse API.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_Converse.html
 */
enum ServiceTier: string implements ServiceTierContract
{
    case Priority = 'priority';
    case Default = 'default';
    case Flex = 'flex';
    case Reserved = 'reserved';
}
