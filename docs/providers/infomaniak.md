# Infomaniak (Euria) Provider

Infomaniak provides AI services through their Euria platform, offering OpenAI-compatible APIs for text generation, embeddings, image generation, and audio transcription.

## Installation

Install the package and publish the configuration:

```bash
composer require laravel/ai
```

## Configuration

Add the Infomaniak provider to your `config/ai.php`:

```php
'providers' => [
    // ...
    'infomaniak' => [
        'driver' => 'infomaniak',
        'key' => env('INFOMANIAK_API_KEY'),
        'url' => env('INFOMANIAK_URL', 'https://api.infomaniak.com/1/ai'),
        'timeout' => 60,
        'models' => [
            'text' => [
                'default' => 'mixtral',
                'cheapest' => 'mistral-7b',
                'smartest' => 'mixtral',
            ],
            'image' => [
                'default' => 'sd3',
            ],
            'transcription' => [
                'default' => 'whisper-1',
            ],
            'embeddings' => [
                'default' => 'text-embedding-3-small',
                'dimensions' => 1536,
            ],
            'audio' => [
                'default' => 'tts-1',
            ],
        ],
    ],
],
```

Set your API key in your `.env`:

```env
INFOMANIAK_API_KEY=your-api-key
INFOMANIAK_URL=https://api.infomaniak.com/1/ai
```

## Available Features

### Text Generation

```php
use Laravel\Ai\Facades\Ai;

$response = Ai::text('Explain quantum computing')->generate(provider: 'infomaniak');
echo $response->text;
```

### Embeddings

```php
use Laravel\Ai\Embeddings;

$response = Embeddings::for(['Hello', 'World'])
    ->generate(provider: 'infomaniak', model: 'text-embedding-3-small');

$vector = $response->first(); // 1536-dimensional vector
```

### Image Generation

```php
use Laravel\Ai\Image;

$response = Image::of('A beautiful Swiss landscape')
    ->generate(provider: 'infomaniak', model: 'sd3');

$url = $response->first()->url;
```

### Audio Transcription

```php
use Laravel\Ai\Transcription;

$response = Transcription::of('/path/to/audio.mp3')
    ->generate(provider: 'infomaniak', model: 'whisper-1');

echo $response->text;
echo $response->language; // if available
```

## Supported Models

| Type | Models |
|------|--------|
| Text | `mixtral`, `mistral-7b`, `openai/gpt-oss-120b` (via gateway) |
| Image | `sd3`, `sdxl` |
| Transcription | `whisper-1` |
| Embeddings | `text-embedding-3-small`, `text-embedding-3-large` |

## API Compatibility

Infomaniak's Euria platform is OpenAI-compatible, meaning the API endpoints follow OpenAI's structure:

- Text: `/openai/chat/completions`
- Embeddings: `/openai/embeddings`
- Images: `/openai/images/generations`
- Transcription: `/openai/audio/transcriptions`

## Timeout Configuration

You can configure the default timeout in the provider config or per-request:

```php
$response = Ai::text('Hello')
    ->generate(provider: 'infomaniak', timeout: 45);
```

## Error Handling

The provider throws `Laravel\Ai\Exceptions\AiException` on API errors with the message format: `Infomaniak Error: [error_type] error_message`.
