<?php

use Laravel\Ai\Provider;

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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure optional multi-tenancy support for agent conversations. When
    | enabled, conversations and messages will be scoped by tenant, ensuring
    | proper data isolation in multi-tenant applications. This feature is
    | disabled by default to maintain backward compatibility.
    |
    */

    'multi_tenancy' => [
        /*
         | Enable or disable multi-tenancy support for agent conversations.
         | When disabled, the package works as a single-tenant application.
         */
        'enabled' => env('AI_MULTI_TENANCY_ENABLED', false),

        /*
         | The column name to use for tenant identification in the database.
         | Common values: 'tenant_id', 'organization_id', 'workspace_id'
         */
        'column' => env('AI_TENANT_COLUMN', 'tenant_id'),

        /*
         | The foreign table that the tenant column references.
         | Set to null if you don't want a foreign key constraint.
         | Example: 'tenants', 'organizations', 'workspaces'
         */
        'foreign_table' => env('AI_TENANT_FOREIGN_TABLE', null),

        /*
         | Whether to add a foreign key constraint for the tenant column.
         | Only applies if 'foreign_table' is set.
         */
        'foreign_key_constraint' => env('AI_TENANT_FOREIGN_KEY_CONSTRAINT', true),

        /*
         | The action to take when a tenant is deleted.
         | Options: 'cascade', 'restrict', 'set null', 'no action'
         | Only applies if foreign key constraint is enabled.
         */
        'on_delete' => env('AI_TENANT_ON_DELETE', 'set null'),
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
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
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
