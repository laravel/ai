<?php

namespace Laravel\Ai\Enums;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case Cohere = 'cohere';
    case ElevenLabs = 'eleven';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case Jina = 'jina';
    case OpenAI = 'openai';
    case OpenRouter = 'openrouter';
    case XAI = 'xai';
}
