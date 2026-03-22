<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;

class GeminiAudioGateway implements AudioGateway
{
    use Concerns\HandlesRateLimiting;

    public function __construct(
        protected int $timeout = 120,
    ) {}

    /**
     * Voice aliases mapping descriptive names to Gemini voice names.
     *
     * @var array<string, string>
     */
    protected const VOICE_ALIASES = [
        'default-female' => 'Kore',
        'default-male' => 'Puck',
        'female' => 'Kore',
        'male' => 'Puck',
        'female_one' => 'Kore',
        'female_two' => 'Aoede',
        'male_one' => 'Puck',
        'male_two' => 'Charon',
    ];

    /**
     * Generate audio from the given text using Gemini TTS.
     *
     * @throws \RuntimeException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30): AudioResponse
    {
        $apiKey = $provider->providerCredentials()['key'];

        // Parse multi-speaker configuration from voice parameter
        $speechConfig = $this->buildSpeechConfig($voice, $instructions);

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $apiKey,
        ])->timeout($this->timeout)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
            'contents' => [
                ['parts' => [['text' => $text]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => $speechConfig,
            ],
        ])->throw());

        $responseData = $response->json();

        // Extract audio data from response
        $audioData = $responseData['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

        if (! $audioData) {
            throw new \RuntimeException('No audio data received from Gemini API');
        }

        return new AudioResponse(
            $audioData,
            new Meta($provider->name(), $model),
            'audio/wav' // Gemini returns PCM/WAV format
        );
    }

    /**
     * Build the speech configuration for Gemini TTS.
     *
     * @return array<string, mixed>
     */
    protected function buildSpeechConfig(string $voice, ?string $instructions): array
    {
        // Check if voice contains multi-speaker configuration (JSON format)
        if ($this->isMultiSpeakerConfig($voice)) {
            return $this->buildMultiSpeakerConfig($voice, $instructions);
        }

        // Single speaker configuration
        return $this->buildSingleSpeakerConfig($voice, $instructions);
    }

    /**
     * Check if the voice parameter contains multi-speaker configuration.
     */
    protected function isMultiSpeakerConfig(string $voice): bool
    {
        return str_starts_with(trim($voice), '{') || str_starts_with(trim($voice), '[');
    }

    /**
     * Build single-speaker configuration.
     *
     * @return array<string, mixed>
     */
    protected function buildSingleSpeakerConfig(string $voice, ?string $instructions): array
    {
        $config = [
            'voiceConfig' => [
                'prebuiltVoiceConfig' => [
                    'voiceName' => $this->resolveVoiceName($voice),
                ],
            ],
        ];

        // Add instructions if provided
        if ($instructions) {
            $config['voiceConfig']['prebuiltVoiceConfig']['instructions'] = $instructions;
        }

        return $config;
    }

    /**
     * Build multi-speaker configuration from JSON.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function buildMultiSpeakerConfig(string $voice, ?string $instructions): array
    {
        $speakers = json_decode($voice, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON format for multi-speaker configuration');
        }

        // Ensure we have an array of speakers
        if (! is_array($speakers)) {
            throw new InvalidArgumentException('Multi-speaker configuration must be an array');
        }

        $speakerConfigs = [];

        foreach ($speakers as $speaker) {
            $speakerName = $speaker['speaker'] ?? $speaker['name'] ?? 'Speaker'.(count($speakerConfigs) + 1);
            $voiceName = $this->resolveVoiceName($speaker['voice'] ?? $speaker['voiceName'] ?? 'female');

            $speakerConfig = [
                'speaker' => $speakerName,
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => $voiceName,
                    ],
                ],
            ];

            // Add speaker-specific instructions if provided
            if (isset($speaker['instructions'])) {
                $speakerConfig['voiceConfig']['prebuiltVoiceConfig']['instructions'] = $speaker['instructions'];
            }

            $speakerConfigs[] = $speakerConfig;
        }

        $config = [
            'multiSpeakerVoiceConfig' => [
                'speakerVoiceConfigs' => $speakerConfigs,
            ],
        ];

        // Add global instructions if provided
        if ($instructions) {
            $config['multiSpeakerVoiceConfig']['instructions'] = $instructions;
        }

        return $config;
    }

    /**
     * Resolve a voice alias to a Gemini voice name.
     */
    protected function resolveVoiceName(string $voice): string
    {
        return self::VOICE_ALIASES[$voice] ?? $voice;
    }
}