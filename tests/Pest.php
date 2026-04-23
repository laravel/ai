<?php

use Tests\Feature\Providers\Anthropic\AnthropicHelpers;
use Tests\Feature\Providers\AzureOpenAi\AzureOpenAiHelpers;
use Tests\Feature\Providers\Gemini\GeminiHelpers;
use Tests\Feature\Providers\Groq\GroqHelpers;
use Tests\Feature\Providers\LiteLLM\LiteLLMHelpers;
use Tests\Feature\Providers\Mistral\MistralHelpers;
use Tests\Feature\Providers\Ollama\OllamaHelpers;
use Tests\Feature\Providers\OpenAi\OpenAiHelpers;
use Tests\Feature\Providers\OpenRouter\OpenRouterHelpers;
use Tests\Feature\Providers\Xai\XaiHelpers;
use Tests\TestCase;

require __DIR__.'/Expectations.php';
require_once __DIR__.'/Helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__), ['.env', '.env.testing'])->safeLoad();

pest()->extend(TestCase::class)->in('Feature', 'Integration');
pest()->use(AnthropicHelpers::class)->group('provider-anthropic')->in('Feature/Providers/Anthropic');
pest()->use(AzureOpenAiHelpers::class)->group('provider-azure')->in('Feature/Providers/AzureOpenAi');
pest()->use(GeminiHelpers::class)->group('provider-gemini')->in('Feature/Providers/Gemini');
pest()->use(GroqHelpers::class)->group('provider-groq')->in('Feature/Providers/Groq');
pest()->use(MistralHelpers::class)->group('provider-mistral')->in('Feature/Providers/Mistral');
pest()->use(OllamaHelpers::class)->group('provider-ollama')->in('Feature/Providers/Ollama');
pest()->use(OpenAiHelpers::class)->group('provider-openai')->in('Feature/Providers/OpenAi');
pest()->use(XaiHelpers::class)->group('provider-xai')->in('Feature/Providers/Xai');
pest()->use(OpenRouterHelpers::class)->group('provider-openrouter')->in('Feature/Providers/OpenRouter');
pest()->use(LiteLLMHelpers::class)->group('provider-litellm')->in('Feature/Providers/LiteLLM');
uses()->group('providers')->in('Feature/Providers');

uses()->group('integration')->in('Integration');
