<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

class TranscriptionTest extends MistralTestCase
{
    public function test_transcription_request_posts_to_correct_endpoint(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
                && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');
        });
    }

    public function test_transcription_response_text_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse('Hello, world!')]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function test_transcription_includes_model_in_request(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request) {
            return str_contains($request->body(), 'voxtral-small-latest');
        });
    }

    public function test_transcription_sends_language_when_provided(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('en')
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request) {
            return str_contains($request->body(), 'language')
                && str_contains($request->body(), 'en');
        });
    }

    public function test_transcription_sends_bearer_token(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_transcription_usage_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello',
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        $this->assertSame(100, $response->usage->promptTokens);
        $this->assertSame(50, $response->usage->completionTokens);
    }

    protected function fakeTranscriptionResponse(string $text = 'Hello, world!')
    {
        return Http::response([
            'text' => $text,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }
}
