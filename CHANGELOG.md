# Changelog

## [Unreleased]

### Added
- Infomaniak (Euria) provider support ([#Add-Euria](https://github.com/laravel/ai/pull/...))
  - Text generation with streaming support
  - Embeddings generation via OpenAI-compatible API
  - Image generation (Stable Diffusion models)
  - Audio transcription (Whisper models)
  - Full test coverage for all Infomaniak features

### Changed
- Updated `TextProvider` interface to include `config()`, `name()`, and `driver()` methods
- Updated `EmbeddingProvider` interface to include `config()`, `name()`, and `driver()` methods
- Updated `ImageProvider` interface to include `config()`, `name()`, and `driver()` methods
- Updated `TranscriptionProvider` interface to include `config()`, `name()`, and `driver()` methods

### Fixed
- Fixed Pint `concat_space` errors in Infomaniak Gateway files
- Fixed PHPStan errors in Infomaniak Gateway Concerns
- Fixed test failures in `AudioFakeTest.php` and `TranscriptionFakeTest.php` related to non-existent `Lab::ElevenLabs`

### Removed
- Removed unused `CreatesGroqClient.php` trait from Infomaniak Concerns
