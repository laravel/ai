<?php

use Tests\Feature\Providers\Anthropic\AnthropicHelpers;
use Tests\Feature\Providers\Gemini\GeminiHelpers;
use Tests\Feature\Providers\Groq\GroqHelpers;
use Tests\Feature\Providers\Mistral\MistralHelpers;
use Tests\Feature\Providers\OpenAi\OpenAiHelpers;
use Tests\Feature\Providers\Xai\XaiHelpers;
use Tests\TestCase;

require __DIR__.'/Expectations.php';
require __DIR__.'/Helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__), ['.env', '.env.testing'])->safeLoad();

pest()->extend(TestCase::class)->in('Feature', 'Integration');
pest()->use(AnthropicHelpers::class)->group('provider-anthropic')->in('Feature/Providers/Anthropic');
pest()->use(GeminiHelpers::class)->group('provider-gemini')->in('Feature/Providers/Gemini');
pest()->use(GroqHelpers::class)->group('provider-groq')->in('Feature/Providers/Groq');
pest()->use(MistralHelpers::class)->group('provider-mistral')->in('Feature/Providers/Mistral');
pest()->use(OpenAiHelpers::class)->group('provider-openai')->in('Feature/Providers/OpenAi');
pest()->use(XaiHelpers::class)->group('provider-xai')->in('Feature/Providers/Xai');

uses()->group('providers')->in('Feature/Providers');
uses()->group('provider-openrouter')->in('Feature/Providers/OpenRouter');

uses()->group('integration')->in('Integration');
