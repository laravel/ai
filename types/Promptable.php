<?php

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;

use function Laravel\Ai\agent;
use function PHPStan\Testing\assertType;

assertType(AgentResponse::class, (new AssistantAgent)->prompt('Hello'));
assertType(StructuredAgentResponse::class, (new StructuredAgent)->prompt('Hello'));
assertType(StructuredAgentResponse::class, StructuredAgent::make()->prompt('Hello'));

assertType(AgentResponse::class, agent('You are helpful.')->prompt('Hello'));
assertType(StructuredAgentResponse::class, agent(schema: fn ($schema) => [])->prompt('Hello'));
