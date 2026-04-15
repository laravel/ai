<?php

dataset('text-providers', ['anthropic', 'gemini', 'groq', 'openai']);

dataset('providers-with-urls', [
    'anthropic' => ['anthropic', 'api.anthropic.com'],
    'gemini' => ['gemini', 'generativelanguage.googleapis.com'],
    'groq' => ['groq', 'api.groq.com'],
    'openai' => ['openai', 'api.openai.com'],
]);

dataset('embedding-providers', [
    'openai' => ['openai', 'OPENAI_API_KEY', 1536],
    'voyageai' => ['voyageai', 'VOYAGEAI_API_KEY', 1024],
]);

dataset('reranking-providers', [
    'cohere' => ['cohere', 'COHERE_API_KEY'],
    'voyageai' => ['voyageai', 'VOYAGEAI_API_KEY'],
]);

dataset('agent-providers', [
    'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
    'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano'],
    'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3-flash-preview'],
    'groq' => ['groq', 'GROQ_API_KEY', 'openai/gpt-oss-20b'],
    'deepseek' => ['deepseek', 'DEEPSEEK_API_KEY', 'deepseek-chat'],
    'mistral' => ['mistral', 'MISTRAL_API_KEY', 'mistral-medium-latest'],
    'xai' => ['xai', 'XAI_API_KEY', 'grok-4-1-fast-reasoning'],
    'openrouter' => ['openrouter', 'OPENROUTER_API_KEY', 'openai/gpt-oss-20b:nitro'],
]);

dataset('agent-document-providers', [
    'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
    'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano'],
    'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite-preview'],
]);
