<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandboxes
    |--------------------------------------------------------------------------
    |
    | A sandbox is a filesystem and shell your application creates, attaches
    | to by ID, and deletes. The local provider runs commands on this host,
    | where "isolate" confines writes with sandbox-exec or bwrap. The other
    | providers run each sandbox in its own container or virtual machine.
    |
    */

    'default_sandbox' => env('AI_SANDBOX', 'local'),

    'sandboxes' => [
        'local' => [
            'driver' => 'local',
            'network' => env('AI_SANDBOX_NETWORK', true),
            'env' => [],
            'timeout' => env('AI_SANDBOX_TIMEOUT', 120),
            'root' => env('AI_SANDBOX_ROOT', storage_path('app/sandboxes')),
            'isolate' => env('AI_SANDBOX_ISOLATE', true),
        ],

        'docker' => [
            'driver' => 'docker',
            'image' => env('AI_SANDBOX_IMAGE', 'ubuntu:24.04'),
            'workdir' => '/workspace',
            'cpus' => 1,
            'memory' => 512,
            'network' => env('AI_SANDBOX_NETWORK', false),
            'env' => [],
            'timeout' => env('AI_SANDBOX_TIMEOUT', 120),
            'binary' => env('AI_SANDBOX_DOCKER_BINARY', 'docker'),
        ],

        'boat' => [
            'driver' => 'boat',
            'key' => env('BOAT_API_KEY'),
            'ttl' => 900,
            'env' => [],
            'timeout' => env('AI_SANDBOX_TIMEOUT', 120),
            'type' => 'small',
            'no_env' => true,
        ],

        'cloudflare' => [
            'driver' => 'cloudflare',
            'url' => env('CLOUDFLARE_SANDBOX_URL'),
            'key' => env('CLOUDFLARE_SANDBOX_API_KEY'),
            'timeout' => env('AI_SANDBOX_TIMEOUT', 120),
        ],

        'boxlite' => [
            'driver' => 'boxlite',
            'url' => env('BOXLITE_URL', 'http://localhost:8100'),
            'key' => env('BOXLITE_API_KEY'),
            'image' => env('AI_SANDBOX_IMAGE', 'alpine:latest'),
            'workdir' => '/workspace',
            'cpus' => 1,
            'memory' => 512,
            'ttl' => 900,
            'env' => [],
            'timeout' => env('AI_SANDBOX_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-6-sol'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-2.5-flare'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
