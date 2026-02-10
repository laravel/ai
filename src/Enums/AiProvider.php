<?php

namespace Laravel\Ai\Enums;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case Cohere = 'cohere';
    case DeepSeek = 'deepseek';
    case ElevenLabs = 'eleven';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case Jina = 'jina';
    case Mistral = 'mistral';
    case Ollama = 'ollama';
    case OpenAI = 'openai';
    case OpenRouter = 'openrouter';
    case VoyageAI = 'voyageai';
    case XAI = 'xai';
}
