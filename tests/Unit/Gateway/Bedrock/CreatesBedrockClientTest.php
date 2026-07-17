<?php

use Aws\MockHandler;
use Aws\Result;
use Aws\Sts\StsClient;
use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;

function bedrockClientTrait(?MockHandler $handler = null): object
{
    return new class($handler)
    {
        use CreatesBedrockClient;

        /** @var array<string, mixed> */
        public array $stsSourceConfig = [];

        public function __construct(protected ?MockHandler $handler = null) {}

        public function resolve(array $credentials, array $config): array
        {
            return $this->resolveAuthConfig($credentials, $config);
        }

        protected function createStsClient(array $credentials, array $config): StsClient
        {
            $this->stsSourceConfig = $this->resolveSourceAuthConfig($credentials, $config);

            return new StsClient([
                'region' => $config['region'] ?? 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'source-key', 'secret' => 'source-secret'],
                'handler' => $this->handler,
            ]);
        }
    };
}

function stsAssumeRoleResult(): Result
{
    return new Result([
        'Credentials' => [
            'AccessKeyId' => 'ASIA-ASSUMED',
            'SecretAccessKey' => 'assumed-secret',
            'SessionToken' => 'assumed-token',
            'Expiration' => new DateTime('+1 hour'),
        ],
    ]);
}

function assumeRoleConfig(array $assumeRole = [], array $config = []): array
{
    return [
        'region' => 'us-west-2',
        'assume_role' => [...['arn' => 'arn:aws:iam::123456789012:role/test-role'], ...$assumeRole],
        ...$config,
    ];
}

test('bearer token credentials use http bearer auth scheme', function () {
    $config = bedrockClientTrait()->resolve(
        ['key' => 'bedrock-token'],
        [],
    );

    expect($config)->toEqual([
        'token' => ['token' => 'bedrock-token'],
        'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
    ]);
});

test('bearer token takes priority over iam credentials', function () {
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

test('iam credentials produce key and secret', function () {
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

test('session token is included when provided with iam credentials', function () {
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

test('empty credentials with default provider enabled returns empty config', function () {
    $config = bedrockClientTrait()->resolve([], []);

    expect($config)->toEqual([]);
});

test('empty credentials with default provider disabled returns false credentials', function () {
    $config = bedrockClientTrait()->resolve(
        [],
        ['use_default_credential_provider' => false],
    );

    expect($config)->toEqual(['credentials' => false]);
});

test('partial iam credentials fall back to default provider', function () {
    $config = bedrockClientTrait()->resolve(
        ['access_key_id' => 'AKIA123'],
        [],
    );

    expect($config)->toEqual([]);
});

test('assume role produces a callable credential provider', function () {
    $config = bedrockClientTrait()->resolve([], assumeRoleConfig());

    expect($config)->toHaveKey('credentials')
        ->and($config['credentials'])->toBeCallable();
});

test('assume role sends the configured parameters to sts', function () {
    $handler = new MockHandler([stsAssumeRoleResult()]);

    $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig([
        'session_name' => 'my-session',
        'duration_seconds' => 900,
        'external_id' => 'ext-123',
    ]));

    $credentials = ($config['credentials'])()->wait();

    expect($handler->getLastCommand()->getName())->toBe('AssumeRole')
        ->and($handler->getLastCommand()->toArray())->toMatchArray([
            'RoleArn' => 'arn:aws:iam::123456789012:role/test-role',
            'RoleSessionName' => 'my-session',
            'DurationSeconds' => 900,
            'ExternalId' => 'ext-123',
        ])
        ->and($credentials->getAccessKeyId())->toBe('ASIA-ASSUMED')
        ->and($credentials->getSecurityToken())->toBe('assumed-token');
});

test('assume role omits duration seconds when not configured', function () {
    $handler = new MockHandler([stsAssumeRoleResult()]);

    $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

    ($config['credentials'])()->wait();

    expect($handler->getLastCommand()->toArray())->not->toHaveKey('DurationSeconds');
});

test('assume role omits external id when not configured', function () {
    $handler = new MockHandler([stsAssumeRoleResult()]);

    $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

    ($config['credentials'])()->wait();

    expect($handler->getLastCommand()->toArray())->not->toHaveKey('ExternalId');
});

test('assume role uses a stable default session name', function () {
    $handler = new MockHandler([stsAssumeRoleResult(), stsAssumeRoleResult()]);

    $first = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());
    $second = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

    ($first['credentials'])()->wait();
    $firstSession = $handler->getLastCommand()->toArray()['RoleSessionName'];

    ($second['credentials'])()->wait();
    $secondSession = $handler->getLastCommand()->toArray()['RoleSessionName'];

    expect($firstSession)->toBe('laravel-ai-bedrock')
        ->and($secondSession)->toBe($firstSession);
});

test('assume role credentials are memoized across client creations', function () {
    $handler = new MockHandler([stsAssumeRoleResult()]);

    $trait = bedrockClientTrait($handler);

    $first = $trait->resolve([], assumeRoleConfig());
    $second = $trait->resolve([], assumeRoleConfig());

    expect($first['credentials'])->toBe($second['credentials']);

    $firstCredentials = ($first['credentials'])()->wait();
    $secondCredentials = ($second['credentials'])()->wait();

    expect($handler->count())->toBe(0)
        ->and($secondCredentials->getAccessKeyId())->toBe($firstCredentials->getAccessKeyId());
});

test('assume role providers are memoized per region and role', function () {
    $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

    $west = $trait->resolve([], assumeRoleConfig());
    $east = $trait->resolve([], assumeRoleConfig(config: ['region' => 'us-east-1']));

    expect($west['credentials'])->not->toBe($east['credentials']);
});

test('assume role is not used when arn is empty', function () {
    $config = bedrockClientTrait()->resolve([], ['assume_role' => ['arn' => null]]);

    expect($config)->toEqual([]);
});

test('assume role is not used when arn is an empty string', function () {
    $config = bedrockClientTrait()->resolve([], ['assume_role' => ['arn' => '']]);

    expect($config)->toEqual([]);
});

test('assume role is not used when not configured at all', function () {
    $config = bedrockClientTrait()->resolve([], ['region' => 'us-west-2']);

    expect($config)->toEqual([]);
});

test('bearer token takes priority over assume role', function () {
    $config = bedrockClientTrait()->resolve(['key' => 'bedrock-token'], assumeRoleConfig());

    expect($config)->toHaveKey('token')
        ->and($config)->not->toHaveKey('credentials');
});

test('assume role takes priority over static iam credentials', function () {
    $config = bedrockClientTrait()->resolve(
        ['access_key_id' => 'AKIA123', 'secret_access_key' => 'secret'],
        assumeRoleConfig(),
    );

    expect($config['credentials'])->toBeCallable();
});

test('static iam credentials are used as the assume role source', function () {
    $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

    $trait->resolve(
        ['access_key_id' => 'AKIA123', 'secret_access_key' => 'secret', 'session_token' => 'session'],
        assumeRoleConfig(),
    );

    expect($trait->stsSourceConfig)->toEqual([
        'credentials' => ['key' => 'AKIA123', 'secret' => 'secret', 'token' => 'session'],
    ]);
});

test('assume role source uses the default credential chain when no static credentials exist', function () {
    $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

    $trait->resolve([], assumeRoleConfig());

    expect($trait->stsSourceConfig)->toEqual([]);
});

test('assume role source honors a disabled default credential provider', function () {
    $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

    $trait->resolve([], assumeRoleConfig(config: ['use_default_credential_provider' => false]));

    expect($trait->stsSourceConfig)->toEqual(['credentials' => false]);
});
