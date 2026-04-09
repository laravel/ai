<?php

use Tests\Feature\Providers\Anthropic\AnthropicHelpers;
use Tests\Feature\Providers\Gemini\GeminiHelpers;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(AnthropicHelpers::class)->in('Feature/Providers/Anthropic');
uses(GeminiHelpers::class)->in('Feature/Providers/Gemini');
