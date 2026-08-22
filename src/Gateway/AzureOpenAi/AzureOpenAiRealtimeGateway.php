<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Gateway\RealtimeGateway;
use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\RealtimeSession;

class AzureOpenAiRealtimeGateway implements RealtimeGateway
{
    use CreatesAzureOpenAiClient;
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

        $session = array_filter([
            'type' => 'realtime',
            'model' => $prompt->model,
            'instructions' => $prompt->instructions,
            'modalities' => $prompt->modalities,
            'tools' => filled($prompt->tools) ? $this->mapTools($prompt->tools, $provider) : null,
            'tool_choice' => $prompt->options['tool_choice'] ?? null,
            'temperature' => $prompt->options['temperature'] ?? null,
            'max_response_output_tokens' => $prompt->options['max_response_output_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if ($voice !== null || isset($prompt->options['output_audio_format'])) {
            $session['audio']['output'] = array_filter([
                'voice' => $voice,
                'format' => $prompt->options['output_audio_format'] ?? null,
            ], fn ($value) => $value !== null);
        }

        if (isset($prompt->options['input_audio_format']) || isset($prompt->options['input_audio_transcription']) || isset($prompt->options['turn_detection'])) {
            $session['audio']['input'] = array_filter([
                'format' => $prompt->options['input_audio_format'] ?? null,
                'transcription' => $prompt->options['input_audio_transcription'] ?? null,
                'turn_detection' => $prompt->options['turn_detection'] ?? null,
            ], fn ($value) => $value !== null);
        }

        $extraOptions = Arr::except($prompt->options, [
            'model', 'modalities', 'instructions', 'voice', 'tools',
            'input_audio_format', 'output_audio_format', 'input_audio_transcription',
            'turn_detection', 'tool_choice', 'temperature', 'max_response_output_tokens',
            'session',
        ]);

        $session = array_merge($session, $prompt->options['session'] ?? [], $extraOptions);

        $payload = [
            'session' => $session,
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $prompt->timeout)->post('realtime/client_secrets', $payload),
        );

        $data = $response->json();

        $clientSecret = $data['value']
            ?? (is_array($data['client_secret'] ?? null)
                ? ($data['client_secret']['value'] ?? '')
                : ($data['client_secret'] ?? ''));

        $expiresAt = $data['expires_at']
            ?? (is_array($data['client_secret'] ?? null)
                ? ($data['client_secret']['expires_at'] ?? 0)
                : 0);

        $model = $data['session']['model']
            ?? ($data['model'] ?? $prompt->model);

        $resolvedVoice = $data['session']['audio']['output']['voice']
            ?? ($data['session']['voice'] ?? ($data['voice'] ?? $voice));

        $resolvedModalities = $data['session']['modalities']
            ?? ($data['modalities'] ?? $prompt->modalities);

        return new RealtimeSession(
            id: $data['id'] ?? '',
            clientSecret: $clientSecret,
            expiresAt: $expiresAt,
            model: $model,
            meta: new Meta($provider->name(), $model),
            voice: $resolvedVoice,
            modalities: $resolvedModalities,
            raw: $data,
        );
    }
}
