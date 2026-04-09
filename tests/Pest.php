<?php

use Tests\Feature\Providers\Anthropic\AnthropicHelpers;
use Tests\Feature\Providers\Gemini\GeminiHelpers;
use Tests\TestCase;

require __DIR__.'/Expectations.php';

uses(TestCase::class)->in('Feature', 'Unit');
uses(AnthropicHelpers::class)->in('Feature/Providers/Anthropic');
uses(GeminiHelpers::class)->in('Feature/Providers/Gemini');

uses()->group('provider-anthropic')->in('Feature/Providers/Anthropic');
uses()->group('provider-gemini')->in('Feature/Providers/Gemini');
uses()->group('provider-groq')->in('Feature/Providers/Groq');
uses()->group('provider-openai')->in('Feature/Providers/OpenAi');
uses()->group('providers')->in('Feature/Providers');
