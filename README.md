<p align="center"><img src="/art/logo.svg" alt="Laravel AI SDK Package Logo"></p>

<p align="center">
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/dt/laravel/ai" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/v/laravel/ai" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/ai"><img src="https://img.shields.io/packagist/l/laravel/ai" alt="License"></a>
</p>

## Introduction

The Laravel AI SDK provides a unified, expressive API for interacting with AI providers such as OpenAI, Anthropic, Gemini, and more. With the AI SDK, you can build intelligent agents with tools and structured output, generate images, synthesize and transcribe audio, create vector embeddings, and much more — all using a consistent, Laravel-friendly interface.

## AWS Bedrock

The SDK includes first-class `bedrock` provider support for text generation (including streaming), embeddings, and image generation.

Configure the provider in `config/ai.php` using the built-in `bedrock` block:

- `region` sets your Bedrock runtime region.
- `key` / `secret` / `session_token` enable explicit AWS credentials.
- `use_default_credential_provider` allows IAM role / profile / environment based auth.

Authentication precedence is:

1. explicit credentials (`key` + `secret`, with optional `session_token`)
2. AWS default credential chain when explicit credentials are not provided

## Documentation

Documentation for the Laravel AI SDK can be found on the [Laravel website](https://laravel.com/docs/ai-sdk).

## Contributing

Thank you for considering contributing to Laravel! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/ai/security/policy) on how to report security vulnerabilities.

## License

The Laravel AI SDK is open-sourced software licensed under the [MIT license](LICENSE.md).
