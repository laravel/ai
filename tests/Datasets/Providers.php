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
