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
    'bedrock' => ['bedrock', 'AWS_ACCESS_KEY_ID', 'anthropic.claude-3-5-sonnet-20241022-v2:0'],
    'azure' => ['azure', 'AZURE_OPENAI_API_KEY', 'gpt-5.4-mini'],
    'deepseek' => ['deepseek', 'DEEPSEEK_API_KEY', 'deepseek-chat'],
    'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite-preview'],
    'groq' => ['groq', 'GROQ_API_KEY', 'openai/gpt-oss-20b'],
    'mistral' => ['mistral', 'MISTRAL_API_KEY', 'mistral-small-latest'],
    'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano'],
    'openrouter' => ['openrouter', 'OPENROUTER_API_KEY', 'anthropic/claude-haiku-4.5'],
    'xai' => ['xai', 'XAI_API_KEY', 'grok-4-1-fast-reasoning'],
]);

dataset('agent-document-providers', [
    'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
    'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano'],
    'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite-preview'],
]);
