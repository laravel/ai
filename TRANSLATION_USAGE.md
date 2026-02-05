# Audio Translation Feature

This document provides examples of using the new Audio Translation feature in the Laravel AI SDK.

## Overview

The Translation feature allows you to translate audio files from any language into English using OpenAI's Whisper model. This is different from transcription, which converts audio to text in the original language.

## Basic Usage

### Translate audio from a file path

```php
use Laravel\Ai\Translation;

$translation = Translation::fromPath('/path/to/audio.mp3')->generate();

echo $translation->text; // English translation of the audio
```

### Translate audio from base64

```php
$translation = Translation::fromBase64($base64Audio)->generate();

echo $translation->text;
```

### Translate uploaded audio

```php
use Illuminate\Http\Request;

public function translateAudio(Request $request)
{
    $translation = Translation::fromUpload($request->file('audio'))->generate();
    
    return response()->json([
        'translation' => $translation->text
    ]);
}
```

### Translate audio from storage

```php
$translation = Translation::fromStorage('audio/recording.mp3', 'public')->generate();
```

## Advanced Usage

### Add a prompt to guide translation

You can provide a prompt to help guide the translation:

```php
$translation = Translation::fromPath('/path/to/audio.mp3')
    ->prompt('This is a medical conversation')
    ->generate();
```

### Specify a provider and model

```php
$translation = Translation::fromPath('/path/to/audio.mp3')
    ->generate('openai', 'whisper-1');
```

### Queue translation generation

For large audio files, you can queue the translation:

```php
$response = Translation::fromPath('/path/to/audio.mp3')->queue();

// The translation will be processed in the background
```

## Testing

### Fake translations in tests

```php
use Laravel\Ai\Translation;

Translation::fake([
    'First translation',
    'Second translation',
]);

$translation = Translation::of($audio)->generate();
// Returns 'First translation'

// Assert a translation was generated
Translation::assertGenerated(function ($prompt) {
    return $prompt->audio instanceof SomeAudioClass;
});
```

## API Reference

### Translation Methods

- `Translation::of($audio)` - Create a translation from audio (file, base64, or uploaded)
- `Translation::fromPath($path, $mime = null)` - Create from file path
- `Translation::fromBase64($base64, $mime = null)` - Create from base64 encoded audio
- `Translation::fromStorage($path, $disk = null)` - Create from storage disk
- `Translation::fromUpload($file)` - Create from uploaded file

### PendingTranslationGeneration Methods

- `->prompt($prompt)` - Add a prompt to guide translation
- `->generate($provider = null, $model = null)` - Generate the translation
- `->queue($provider = null, $model = null)` - Queue the translation generation

### Response Properties

- `$translation->text` - The translated text in English
- `$translation->usage` - Token usage information
- `$translation->meta` - Metadata about the provider and model used

## Configuration

Set the default provider for translations in `config/ai.php`:

```php
'default_for_translation' => 'openai',
```

## Supported Providers

Currently, only OpenAI's Whisper model supports audio translation to English.
