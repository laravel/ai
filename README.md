<p align="center"><img src="/art/logo.svg" alt="Laravel AI Package Logo"></p>

<p align="center">
<a href="https://github.com/laravel/ai/actions"><img src="https://github.com/laravel/ai/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/dt/laravel/ai" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/v/laravel/ai" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/l/laravel/ai" alt="License"></a>
</p>

## Introduction

The official Laravel AI SDK.

- [Installation](#installation)
- [Agents](#agents)
    - [Conversation Context](#conversation-context)
    - [Tools](#tools)
    - [Structured Output](#structured-output)
    - [Streaming](#streaming)

## Installation

You can install the Laravel AI SDK via Composer:

```shell
composer require laravel/ai
```

Or, if this package has not been publicly released yet, you can install it via a Composer "path" repository. First, clone this repository to your local machine, then add the path repository to your application's `composer.json` file:

```json
"repositories": [
    {
        "type": "path",
        "url": "./../laravel-ai"
    }
],
```

Then, add `"laravel/ai": "*"` to your Composer dependencies. You will likely also need to adjust your application's `minimum-stability` to `dev`. Finally, run `composer update`.

## Agents

You can create an agent via the package's Artisan commands:

```shell
php artisan make:agent SalesCoach

php artisan make:agent SalesCoach --structured
```

Within the generated agent class, you can define the system prompt / instructions, message context, available tools, and output schema (if applicable).

To prompt an agent, you may use the various methods provided by the agent's `Promptable` trait:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...');

return (string) $response;
```

### Conversation Context

If your agent implements the `Conversational` interface, you may use the `messages` method to return the previous conversation context, if applicable:

```php
use App\Models\History;
use Laravel\Ai\Messages\Message;

/**
 * Get the list of messages comprising the conversation so far.
 */
public function messages(): iterable
{
    return History::where('user_id', $this->user->id)
        ->latest()
        ->limit(50)
        ->get()
        ->reverse()
        ->map(function ($message) {
            return new Message($message->role, $message->content);
        })->all();
}
```

### Tools

Tools may be used to give agents additional functionality that they can utilize while responding to prompts. Tools can be created using the `make:tool` Artisan command:

```shell
php artisan make:tool RandomNumberGenerator
```

The generated tool will be placed in your application's `app/Ai/Tools` directory. Each tool contains a `handle` method that will be invoked by the agent when it needs to utilize the tool:

```php
<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;

class RandomNumberGenerator implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool may be used to generate cryptographically secure random numbers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(array $input): string
    {
        return (string) random_int($input['min'], $input['max']);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'min' => $schema->integer()->min(0)->required(),
            'max' => $schema->integer()->required(),
        ];
    }
}
```

Once you have defined your tool, you may return it from the `tools` method of any of your agents:

```php
use App\Ai\Tools\RandomNumberGenerator;

/**
 * Get the tools available to the agent.
 *
 * @return Tool[]
 */
public function tools(): iterable
{
    return [
        new RandomNumberGenerator,
    ];
}
```

### Structured Output

If you would like your agent to return structured output, implement the `HasStructuredOutput` interface, which requires that your agent define a `schema` method:

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class SalesCoach implements Agent, HasStructuredOutput
{
    use Promptable;

    // ...

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required(),
        ];
    }
}
```

When prompting an agent that returns structured output, you can access the returned `StructuredAgentResponse` like an array:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...');

return $response['score'];
```

### Streaming

You may stream an agent's response by invoking the `stream` method. The returned `StreamableAgentResponse` may be returned from a route to automatically send a streaming response to the client:

```php
Route::get('/coach', function () {
    return (new SalesCoach)->stream('Analyze this sales transcript...');
});
```

Alternatively, you may iterate through the streamed events manually:

```php
$stream (new SalesCoach)->stream('Analyze this sales transcript...');

foreach ($stream as $event) {
    // ...
}
```

## Contributing

Thank you for considering contributing to Laravel! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/pennant/security/policy) on how to report security vulnerabilities.

## License

The Laravel AI SDK is open-sourced software licensed under the [MIT license](LICENSE.md).
