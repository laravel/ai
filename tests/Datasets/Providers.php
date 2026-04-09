<?php

dataset('text-providers', ['anthropic', 'gemini', 'groq', 'openai']);

dataset('providers-with-urls', [
    'anthropic' => ['anthropic', 'api.anthropic.com'],
    'gemini' => ['gemini', 'generativelanguage.googleapis.com'],
    'groq' => ['groq', 'api.groq.com'],
    'openai' => ['openai', 'api.openai.com'],
]);
