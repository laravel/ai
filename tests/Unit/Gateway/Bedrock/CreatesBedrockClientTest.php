<?php

use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;

function bedrockClientTrait(): object
{
    return new class
    {
        use CreatesBedrockClient;

        public function resolve(array $credentials, array $config): array
        {
            return $this->resolveAuthConfig($credentials, $config);
        }
    };
}

test('bearer token credentials use http bearer auth scheme', function (): void {
    $config = bedrockClientTrait()->resolve(
        ['key' => 'bedrock-token'],
        [],
    );

    expect($config)->toEqual([
        'token' => ['token' => 'bedrock-token'],
        'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
    ]);
});

test('bearer token takes priority over iam credentials', function (): void {
    $config = bedrockClientTrait()->resolve(
        [
            'key' => 'bedrock-token',
            'access_key_id' => 'ignored-key',
            'secret_access_key' => 'ignored-secret',
        ],
        [],
    );

    expect($config)->toHaveKey('token')
        ->and($config)->not->toHaveKey('credentials');
});

test('iam credentials produce key and secret', function (): void {
    $config = bedrockClientTrait()->resolve(
        [
            'access_key_id' => 'AKIA123',
            'secret_access_key' => 'secret',
        ],
        [],
    );

    expect($config)->toEqual([
        'credentials' => [
            'key' => 'AKIA123',
            'secret' => 'secret',
        ],
    ]);
});

test('session token is included when provided with iam credentials', function (): void {
    $config = bedrockClientTrait()->resolve(
        [
            'access_key_id' => 'AKIA123',
            'secret_access_key' => 'secret',
            'session_token' => 'session',
        ],
        [],
    );

    expect($config['credentials'])->toEqual([
        'key' => 'AKIA123',
        'secret' => 'secret',
        'token' => 'session',
    ]);
});

test('empty credentials with default provider enabled returns empty config', function (): void {
    $config = bedrockClientTrait()->resolve([], []);

    expect($config)->toEqual([]);
});

test('empty credentials with default provider disabled returns false credentials', function (): void {
    $config = bedrockClientTrait()->resolve(
        [],
        ['use_default_credential_provider' => false],
    );

    expect($config)->toEqual(['credentials' => false]);
});

test('partial iam credentials fall back to default provider', function (): void {
    $config = bedrockClientTrait()->resolve(
        ['access_key_id' => 'AKIA123'],
        [],
    );

    expect($config)->toEqual([]);
});
