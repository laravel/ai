<?php

namespace Laravel\Ai\Gateway\OpenAi;

use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Gateway\RealtimeGateway;
use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\RealtimeSession;

class OpenAiRealtimeGateway implements RealtimeGateway
{
    use CreatesOpenAiClient;
    use HandlesFailoverErrors;
    use MapsTools;

    /**
     * Create an ephemeral realtime session.
     */
    public function createRealtimeSession(
        RealtimeProvider $provider,
        RealtimePrompt $prompt,
    ): RealtimeSession {
        $voice = match ($prompt->voice) {
            'default-female' => 'alloy',
            'default-male' => 'ash',
            default => $prompt->voice,
        };

        $payload = array_filter([
            'model' => $prompt->model,
            'modalities' => $prompt->modalities,
            'instructions' => $prompt->instructions,
            'voice' => $voice,
            'tools' => filled($prompt->tools) ? $this->mapTools($prompt->tools, $provider) : null,
            'input_audio_format' => $prompt->options['input_audio_format'] ?? null,
            'output_audio_format' => $prompt->options['output_audio_format'] ?? null,
            'input_audio_transcription' => $prompt->options['input_audio_transcription'] ?? null,
            'turn_detection' => $prompt->options['turn_detection'] ?? null,
            'tool_choice' => $prompt->options['tool_choice'] ?? null,
            'temperature' => $prompt->options['temperature'] ?? null,
            'max_response_output_tokens' => $prompt->options['max_response_output_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        $extraOptions = Arr::except($prompt->options, [
            'model', 'modalities', 'instructions', 'voice', 'tools',
            'input_audio_format', 'output_audio_format', 'input_audio_transcription',
            'turn_detection', 'tool_choice', 'temperature', 'max_response_output_tokens',
        ]);

        $payload = array_merge($payload, $extraOptions);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $prompt->timeout)->post('realtime/sessions', $payload),
        );

        $data = $response->json();

        $clientSecret = is_array($data['client_secret'] ?? null)
            ? ($data['client_secret']['value'] ?? '')
            : ($data['client_secret'] ?? '');

        $expiresAt = is_array($data['client_secret'] ?? null)
            ? ($data['client_secret']['expires_at'] ?? 0)
            : ($data['expires_at'] ?? 0);

        return new RealtimeSession(
            id: $data['id'] ?? '',
            clientSecret: $clientSecret,
            expiresAt: $expiresAt,
            model: $data['model'] ?? $prompt->model,
            meta: new Meta($provider->name(), $data['model'] ?? $prompt->model),
            voice: $data['voice'] ?? $voice,
            modalities: $data['modalities'] ?? $prompt->modalities,
            raw: $data,
        );
    }
}
