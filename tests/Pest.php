<?php

use Tests\Feature\Providers\Anthropic\AnthropicHelpers;
use Tests\Feature\Providers\Gemini\GeminiHelpers;
use Tests\Feature\Providers\Groq\GroqHelpers;
use Tests\Feature\Providers\Mistral\MistralHelpers;
use Tests\Feature\Providers\OpenAi\OpenAiHelpers;
use Tests\Feature\Providers\Xai\XaiHelpers;
use Tests\TestCase;

require __DIR__.'/Expectations.php';

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->use(AnthropicHelpers::class)->in('Feature/Providers/Anthropic');
pest()->use(GeminiHelpers::class)->in('Feature/Providers/Gemini');
pest()->use(GroqHelpers::class)->in('Feature/Providers/Groq');
pest()->use(MistralHelpers::class)->in('Feature/Providers/Mistral');
pest()->use(OpenAiHelpers::class)->in('Feature/Providers/OpenAi');
pest()->use(XaiHelpers::class)->in('Feature/Providers/Xai');

uses()->group('provider-anthropic')->in('Feature/Providers/Anthropic');
uses()->group('provider-gemini')->in('Feature/Providers/Gemini');
uses()->group('provider-groq')->in('Feature/Providers/Groq');
uses()->group('provider-mistral')->in('Feature/Providers/Mistral');
uses()->group('provider-openai')->in('Feature/Providers/OpenAi');
uses()->group('provider-xai')->in('Feature/Providers/Xai');
uses()->group('providers')->in('Feature/Providers');
