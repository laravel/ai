<?php

dataset('text-providers', ['anthropic', 'gemini', 'groq', 'openai']);

dataset('tts-providers', [
    'openai' => ['openai', 'OPENAI_API_KEY'],
    'gemini' => ['gemini', 'GEMINI_API_KEY'],
    'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
]);

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

dataset('transcription-providers', [
    'openai' => ['openai', 'OPENAI_API_KEY'],
    'gemini' => ['gemini', 'GEMINI_API_KEY'],
    'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
]);

dataset('diarization-providers', [
    'openai' => ['openai', 'OPENAI_API_KEY'],
    'gemini' => ['gemini', 'GEMINI_API_KEY'],
]);

dataset('image-providers', [
    'openai-dall-e-3' => ['openai', 'OPENAI_API_KEY', 'dall-e-3'],
    'openai-gpt-image-2' => ['openai', 'OPENAI_API_KEY', 'gpt-image-2'],
    'xai' => ['xai', 'XAI_API_KEY', 'grok-imagine-image'],
]);

dataset('reranking-providers', [
    'cohere' => ['cohere', 'COHERE_API_KEY'],
    'voyageai' => ['voyageai', 'VOYAGEAI_API_KEY'],
]);

dataset('agent-providers', [
    'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
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

dataset('agent-image-providers', [
    'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano'],
    'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite-preview'],
    'xai' => ['xai', 'XAI_API_KEY', 'grok-4-1-fast-reasoning'],
]);

dataset('tool-replay-providers', [
    // Reasoning model
    'openai-gpt-5-4-nano' => ['openai', 'OPENAI_API_KEY', 'gpt-5.4-nano', true],
    // Non-reasoning model (backward compatibility)
    'openai-gpt-4-1' => ['openai', 'OPENAI_API_KEY', 'gpt-4.1', false],
]);
